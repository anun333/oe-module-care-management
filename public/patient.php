<?php

/**
 * Care Management for one patient: enroll in CCM, PCM, BHI, CoCM, APCM, RPM or RTM with the
 * consent CMS requires, log time and remote monitoring's days of data, and see this month's
 * minutes, the codes they meet and what blocks billing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AccessDeniedHelper;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenHealthcare\CareManagement\Bootstrap;
use OpenHealthcare\CareManagement\Domain\Month;
use OpenHealthcare\CareManagement\Domain\MonthRules;
use OpenHealthcare\CareManagement\Domain\Schedule;
use OpenHealthcare\CareManagement\Domain\Service;
use OpenHealthcare\CareManagement\Guide;
use OpenHealthcare\CareManagement\Labels;
use OpenHealthcare\CareManagement\Repository\DevicePeriodRepository;
use OpenHealthcare\CareManagement\Repository\EnrollmentRepository;
use OpenHealthcare\CareManagement\Repository\MonthRepository;
use OpenHealthcare\CareManagement\Repository\TimeRepository;
use OpenHealthcare\CareManagement\Page;
use OpenHealthcare\Common\OpenEmr;

// Logging care is clinical documentation: the same check as the Staff Worklist's actions.
if (!AclMain::aclCheckCore('patients', 'med', '', 'write')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for patients/med write: Care Management', xl('Care Management'));
}
$session = SessionWrapperFactory::getInstance()->getActiveSession();
$userId = (int)$session->get('authUserID');
$pid = (int)($_GET['pid'] ?? $session->get('pid') ?? 0);
$patient = QueryUtils::fetchRecords('SELECT `pid`, `fname`, `lname`, `pubpid` FROM `patient_data` WHERE `pid` = ?', [$pid])[0] ?? null;
if ($patient === null) {
    ?>
<!DOCTYPE html>
<html lang="<?php echo attr(Page::lang()); ?>" dir="<?php echo attr(Page::dir()); ?>">
<head>
    <title><?php echo xlt('Care Management'); ?></title>
    <?php Header::setupHeader(); ?>
    <?php echo \OpenHealthcare\CareManagement\Contrast::style(); ?>
</head>
<body class="body_top">
<div class="container-fluid py-3">
    <h1 class="h4"><?php echo xlt('Care Management'); ?></h1>
    <div class="alert alert-warning" role="alert" data-state="error"><?php echo xlt('No such patient.'); ?></div>
</div>
</body>
</html>
    <?php
    return;
}
$enrollments = new EnrollmentRepository();
$times = new TimeRepository();
$devices = new DevicePeriodRepository();
$today = new DateTimeImmutable('today');
$month = Month::parse((string)($_GET['month'] ?? '')) ?? Month::of($today);
$practitioners = $enrollments->practitioners();
$problems = $enrollments->problems($pid);
$errors = [];
$done = '';
$date = static fn(mixed $v): ?string => is_string($v) && DateTimeImmutable::createFromFormat('!Y-m-d', $v) !== false
    && DateTimeImmutable::createFromFormat('!Y-m-d', $v)->format('Y-m-d') === $v ? $v : null;
$audit = static function (string $event, string $what) use ($session, $pid): void {
    $user = (string)$session->get('authUser');
    $group = (string)$session->get('authProvider');
    if ($event === 'patient-record-update') {
        EventAuditLogger::getInstance()->newEvent('patient-record-update', $user, $group, 1, "Care Management: $what", $pid);
    } else {
        EventAuditLogger::getInstance()->newEvent('patient-record-select', $user, $group, 1, "Care Management: $what", $pid);
    }
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '', session: $session)) {
        CsrfUtils::csrfNotVerified();
    }
    $action = (string)($_POST['action'] ?? '');
    $mine = [];
    foreach ($enrollments->forPatient($pid) as $e) {
        $mine[$e->id] = $e;
    }
    // CMS's four for CCM, PCM and APCM; BHI and CoCM also need the patient's permission to consult
    // specialists, a psychiatric consultant included (MLN909432). RPM and RTM need the patient's
    // consent, with no list of what they must be told.
    $toldFour = !empty($_POST['told_available']) && !empty($_POST['told_cost']) && !empty($_POST['told_one']) && !empty($_POST['told_stop']);
    $explained = static fn(string $service): bool => in_array($service, Service::REMOTE, true)
        || ($toldFour && (!in_array($service, [Service::BHI, Service::COCM], true) || !empty($_POST['told_specialists'])));
    $consentOn = $date($_POST['consent_on'] ?? null);
    $consentHow = in_array($_POST['consent_how'] ?? '', ['verbal', 'written'], true) ? (string)$_POST['consent_how'] : null;
    if ($action === 'enroll') {
        $service = in_array($_POST['service'] ?? '', Service::ALL, true) ? (string)$_POST['service'] : '';
        $practitioner = (int)($_POST['practitioner'] ?? 0);
        $started = $date($_POST['started_on'] ?? null);
        $chosen = array_map('intval', is_array($_POST['conditions'] ?? null) ? $_POST['conditions'] : []);
        $conditions = array_values(array_filter($problems, static fn(array $p): bool => in_array($p['id'], $chosen, true)));
        if ($service === '' || !isset($practitioners[$practitioner]) || $started === null) {
            $errors[] = xl('Choose the service, the billing practitioner and the start date.');
        }
        if ($consentOn !== null && $consentHow === null) {
            $errors[] = xl('Say whether the consent was written or verbal.');
        }
        $kind = in_array($_POST['device_kind'] ?? '', Service::RTM_KINDS, true) ? (string)$_POST['device_kind'] : null;
        if ($service === Service::RTM && $kind === null) {
            $errors[] = xl('For RTM, choose what it monitors.');
        }
        if ($errors === []) {
            $id = $enrollments->add($pid, $service, $practitioner, $conditions, $consentOn, $consentHow, $consentOn !== null && $explained($service),
                $date($_POST['initiating_visit_on'] ?? null), (string)$started, trim((string)($_POST['note'] ?? '')), $userId, !empty($_POST['qmb']), $kind);
            $audit('patient-record-update', "enrollment $id ($service)");
            $done = xl('Enrolled.');
        }
    } elseif ($action === 'consent' && isset($mine[(int)($_POST['enrollment'] ?? 0)])) {
        $visit = $date($_POST['initiating_visit_on'] ?? null);
        if ($consentOn === null && $consentHow === null && $visit !== null) {
            // Only an initiating visit: PCM's yearly one, or one held after the consent was taken.
            $id = (int)$_POST['enrollment'];
            $enrollments->recordInitiatingVisit($id, $pid, $visit);
            $audit('patient-record-update', "initiating visit $visit for enrollment $id");
            $done = xl('Initiating visit recorded.');
        } elseif ($consentOn === null || $consentHow === null) {
            $errors[] = xl('Enter the consent date and whether it was written or verbal.');
        } else {
            $id = (int)$_POST['enrollment'];
            $enrollments->recordConsent($id, $pid, $consentOn, $consentHow, $explained($mine[$id]->service), $date($_POST['initiating_visit_on'] ?? null), $userId);
            $audit('patient-record-update', "consent for enrollment $id");
            $done = xl('Consent recorded.');
        }
    } elseif ($action === 'complex' && isset($mine[(int)($_POST['enrollment'] ?? 0)])) {
        $e = $mine[(int)$_POST['enrollment']];
        // Complex CCM's decision-making is the billing practitioner's own, so only they record it.
        if ($e->service !== Service::CCM || $userId !== $e->practitionerId) {
            $errors[] = xl('Only the billing practitioner can record their own decision-making.');
        } else {
            $on = !empty($_POST['complex_mdm']);
            (new MonthRepository())->setComplex($e->id, $pid, $month, $on, $userId);
            $audit('patient-record-update', 'enrollment ' . $e->id . ' complex decision-making ' . $month->key() . ($on ? ' recorded' : ' cleared'));
            $done = $on ? xl('Recorded: moderate or high complexity decision-making this month.') : xl('Cleared.');
        }
    } elseif ($action === 'stop' && isset($mine[(int)($_POST['enrollment'] ?? 0)])) {
        $stopped = $date($_POST['stopped_on'] ?? null);
        if ($stopped === null) {
            $errors[] = xl('Enter the date the patient stopped.');
        } else {
            $enrollments->stop((int)$_POST['enrollment'], $pid, $stopped);
            $audit('patient-record-update', 'enrollment ' . (int)$_POST['enrollment'] . " stopped $stopped");
            $done = xl('Stopped. It takes effect at the end of that month.');
        }
    } elseif ($action === 'log' && isset($mine[(int)($_POST['enrollment'] ?? 0)])) {
        $e = $mine[(int)$_POST['enrollment']];
        $served = $date($_POST['served_on'] ?? null);
        $minutes = (int)($_POST['minutes'] ?? 0);
        $activity = trim((string)($_POST['activity'] ?? ''));
        // Only the billing practitioner can log the practitioner's own time.
        $role = !empty($_POST['as_practitioner']) && $userId === $e->practitionerId ? Service::PRACTITIONER : Service::STAFF;
        $interactive = in_array($e->service, Service::REMOTE, true) && !empty($_POST['interactive']);
        if ($served === null || new DateTimeImmutable($served) > $today || !$e->activeIn(Month::of(new DateTimeImmutable($served)))) {
            $errors[] = xl('The date must be today or earlier, in a month the enrollment is active.');
        } elseif ($minutes < 1 || $minutes > 240 || $activity === '') {
            $errors[] = xl('Enter the minutes (1 to 240) and what was done.');
        } else {
            $id = $times->add($pid, $e->id, $served, $minutes, $role, $activity, $userId, $interactive);
            $audit('patient-record-update', "time entry $id ($minutes minutes, $role" . ($interactive ? ', live conversation' : '') . ')');
            $done = xl('Time logged.');
            $month = Month::of(new DateTimeImmutable($served));
        }
    } elseif ($action === 'period' && isset($mine[(int)($_POST['enrollment'] ?? 0)]) && in_array($mine[(int)$_POST['enrollment']]->service, Service::REMOTE, true)) {
        $e = $mine[(int)$_POST['enrollment']];
        $start = $date($_POST['period_start'] ?? null);
        $days = filter_var($_POST['days'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => MonthRules::PERIOD_DAYS]]);
        $startDay = $start === null ? null : new DateTimeImmutable($start);
        // Days can't run past today in a period still under way.
        $elapsed = $startDay === null ? 0 : min(MonthRules::PERIOD_DAYS, (int)$startDay->diff($today)->format('%r%a') + 1);
        $overlap = $startDay === null ? null : DevicePeriodRepository::overlapping($devices->forPatients([$pid]), $startDay);
        if ($startDay === null || $startDay > $today || $startDay < $e->startedOn->setTime(0, 0) || !$e->activeIn(Month::of($startDay))) {
            $errors[] = xl('The period must start today or earlier, on or after the enrollment started, in a month it is active.');
        } elseif ($days === false || $days > $elapsed) {
            $errors[] = sprintf(xl('Enter the days with data, 0 to %s.'), $elapsed);
        } elseif ($overlap !== null) {
            $errors[] = sprintf(xl('This overlaps the 30-day period from %s: only one can be billed in 30 days, for RPM or RTM.'), oeFormatShortDate($overlap->format('Y-m-d')));
        } else {
            $id = $devices->add($pid, $e->id, (string)$start, (int)$days, $userId);
            $audit('patient-record-update', "device period $id ($start, $days days)");
            $done = xl('Device period entered.');
            $month = Month::of($startDay->modify('+' . (MonthRules::PERIOD_DAYS - 1) . ' days'));
            if ($month->first() > $today) {
                $month = Month::of($startDay);
            }
        }
    } elseif ($action === 'remove_period') {
        $id = (int)($_POST['period'] ?? 0);
        $devices->remove($id, $pid, $userId);
        $audit('patient-record-update', "device period $id removed");
        $done = xl('Device period removed.');
    } elseif ($action === 'remove') {
        $id = (int)($_POST['entry'] ?? 0);
        $times->remove($id, $pid, $userId);
        $audit('patient-record-update', "time entry $id removed");
        $done = xl('Entry removed.');
    }
}
$audit('patient-record-select', 'viewed');

$list = $enrollments->forPatient($pid);
$details = $enrollments->details($pid);
$entries = $times->inMonth($month, [$pid]);
$ids = array_map(static fn($e): int => $e->id, $list);
$complex = (new MonthRepository())->complex($ids, $month);
$earlier = $times->hadTimeBefore($ids, $month);
$periods = $devices->forPatients([$pid]);
$schedule = Schedule::forMonth($month);
$moduleUrl = OpenEmr::webRoot() . Bootstrap::MODULE_INSTALLATION_PATH . '/public/';
$token = CsrfUtils::collectCsrfToken(session: $session);
$consentHow = ['verbal' => xl('Verbal'), 'written' => xl('Written')];
$consentFields = static function (string $k) {
    ?>
    <div class="form-row">
        <div class="form-group col-md-3"><label for="con<?php echo attr($k); ?>"><?php echo xlt('Consent date'); ?></label><input type="date" class="form-control form-control-sm" id="con<?php echo attr($k); ?>" name="consent_on"></div>
        <div class="form-group col-md-3"><label for="chow<?php echo attr($k); ?>"><?php echo xlt('Consent was'); ?></label>
            <select class="form-control form-control-sm" id="chow<?php echo attr($k); ?>" name="consent_how"><option value=""></option><option value="verbal"><?php echo xlt('Verbal'); ?></option><option value="written"><?php echo xlt('Written'); ?></option></select></div>
        <div class="form-group col-md-3"><label for="iv<?php echo attr($k); ?>"><?php echo xlt('Initiating visit, if needed'); ?></label><input type="date" class="form-control form-control-sm" id="iv<?php echo attr($k); ?>" name="initiating_visit_on"></div>
    </div>
    <p class="small mb-1"><?php echo xlt('The patient was told (CMS requires the first four, and for BHI and CoCM the fifth too; RPM and RTM need only the consent date and how it was given):'); ?></p>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="told_available" value="1" id="ta<?php echo attr($k); ?>"><label class="form-check-label small" for="ta<?php echo attr($k); ?>"><?php echo xlt('that the service is available to them'); ?></label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="told_cost" value="1" id="tc<?php echo attr($k); ?>"><label class="form-check-label small" for="tc<?php echo attr($k); ?>"><?php echo xlt('that cost-sharing (coinsurance) applies'); ?></label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="told_one" value="1" id="to<?php echo attr($k); ?>"><label class="form-check-label small" for="to<?php echo attr($k); ?>"><?php echo xlt('that only one practitioner can furnish and bill it in a calendar month'); ?></label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="told_stop" value="1" id="ts<?php echo attr($k); ?>"><label class="form-check-label small" for="ts<?php echo attr($k); ?>"><?php echo xlt('that they can stop at any time, effective at the end of the month'); ?></label></div>
    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="told_specialists" value="1" id="tp<?php echo attr($k); ?>"><label class="form-check-label small" for="tp<?php echo attr($k); ?>"><?php echo xlt('for BHI and CoCM: that the billing practitioner will consult specialists, a psychiatric consultant included, and the patient agreed'); ?></label></div>
    <?php
};
?>
<!DOCTYPE html>
<html lang="<?php echo attr(Page::lang()); ?>" dir="<?php echo attr(Page::dir()); ?>">
<head>
    <title><?php echo xlt('Care Management'); ?></title>
    <?php Header::setupHeader(); ?>
    <?php echo \OpenHealthcare\CareManagement\Contrast::style(); ?>
</head>
<body class="body_top">
<div class="container-fluid py-3">
    <h1 class="h4"><?php echo xlt('Care Management'); ?>: <?php echo text(trim($patient['lname'] . ', ' . $patient['fname'], ', ')); ?>
        <small class="text-muted"><?php echo xlt('ID'); ?> <?php echo text((string)$patient['pubpid']); ?></small></h1>
    <p class="small mb-2"><a href="<?php echo attr(Guide::url()); ?>" target="_blank" rel="noopener"><?php echo xlt('Help'); ?><span class="sr-only"> <?php echo xlt('(opens in a new window)'); ?></span></a></p>
    <?php if ($done !== '') { ?><div class="alert alert-success py-2"><?php echo text($done); ?></div><?php } ?>
    <?php foreach ($errors as $e) { ?><div class="alert alert-danger py-2"><?php echo text($e); ?></div><?php } ?>

    <form method="get" class="form-inline mb-2">
        <input type="hidden" name="pid" value="<?php echo attr((string)$pid); ?>">
        <label class="mr-2" for="month"><?php echo xlt('Month'); ?></label>
        <input type="month" class="form-control form-control-sm mr-2" id="month" name="month" value="<?php echo attr($month->key()); ?>">
        <button class="btn btn-sm btn-outline-secondary" type="submit"><?php echo xlt('Show'); ?></button>
        <a class="ml-3 small" href="<?php echo attr($moduleUrl . 'index.php?month=' . urlencode($month->key())); ?>"><?php echo xlt('All patients this month'); ?></a>
    </form>
    <?php if (!$schedule->covers($month)) { ?>
        <div class="alert alert-warning py-2 small" data-schedule="<?php echo attr((string)$schedule->year); ?>"><?php echo text(sprintf(xl('This version of the module does not have the %1$s fee schedule yet, so this month is counted with the %2$s codes and minutes. Update the module.'), $month->first()->format('Y'), $schedule->year)); ?></div>
    <?php } ?>

    <?php foreach ($list as $e) {
        $d = $details[$e->id] ?? ['consentHow' => '', 'note' => '', 'conditions' => []];
        $active = $e->activeIn($month);
        $s = MonthRules::summarize($e, $entries, $list, $month, $complex[$e->id] ?? false, $earlier[$e->id] ?? false, $periods, $schedule);
        $remote = in_array($e->service, Service::REMOTE, true); ?>
        <div class="card mb-3" data-enrollment="<?php echo attr((string)$e->id); ?>" data-service="<?php echo attr($e->service); ?>">
            <div class="card-body py-2">
                <h2 class="h6 mb-1"><?php echo text(Labels::service($e->service)); ?><?php if ($e->deviceKind !== null) { ?>: <?php echo text(Labels::deviceKind($e->deviceKind)); ?><?php } ?>
                    <small class="text-muted"><?php echo text($practitioners[$e->practitionerId] ?? ('#' . $e->practitionerId)); ?> ·
                        <?php echo xlt('since'); ?> <?php echo text(oeFormatShortDate($e->startedOn->format('Y-m-d'))); ?>
                        <?php if ($e->stoppedOn !== null) { ?> · <?php echo xlt('stopped'); ?> <?php echo text(oeFormatShortDate($e->stoppedOn->format('Y-m-d'))); ?><?php } ?></small></h2>
                <p class="small mb-1"><?php echo xlt('Conditions'); ?>: <?php echo text(implode('; ', array_map(static fn(array $c): string => $c['title'] . ($c['code'] !== '' ? ' (' . $c['code'] . ')' : ''), $d['conditions'])) ?: xl('none chosen')); ?></p>
                <p class="small mb-1"><?php echo xlt('Consent'); ?>:
                    <?php echo $e->consentOn === null ? xlt('not recorded') : text(oeFormatShortDate($e->consentOn->format('Y-m-d')) . ', ' . ($consentHow[$d['consentHow']] ?? $d['consentHow']) . ($e->consentComplete ? '' : ' (' . xl('not everything recorded as told') . ')')); ?></p>
                <?php if ($active) { ?>
                    <p class="mb-1" data-month-minutes="<?php echo attr((string)($s['staff'] + $s['practitioner'])); ?>">
                        <?php echo text(sprintf(xl('%1$s: %2$s minutes by clinical staff, %3$s by the billing practitioner.'), $month->key(), $s['staff'], $s['practitioner'])); ?></p>
                    <?php foreach ($s['routes'] as $route => $codes) { ?>
                        <p class="small mb-1" data-route="<?php echo attr($route); ?>" data-codes="<?php echo attr(Labels::codes($codes)); ?>">
                            <?php echo text(Labels::route($route, $e->service)); ?>:
                            <?php echo match (true) {
                                $codes !== [] => '<strong>' . text(Labels::codes($codes)) . '</strong>',
                                $s['notes'] !== [] => xlt('none, see below'),
                                default => text(sprintf(xl('%1$s more minutes for %2$s'), $s['next'][$route], $s['nextCode'][$route] ?? '')),
                            }; ?></p>
                    <?php } ?>
                    <?php foreach ($s['notes'] as $n) { ?><p class="small text-danger mb-1" data-note="<?php echo attr($n); ?>"><?php echo text(Labels::note($n)); ?></p><?php } ?>
                    <?php if ($remote) { ?>
                        <p class="small text-muted mb-1"><?php echo xlt('Device codes go with each 30-day period, in the month it ends; set-up with the first period that has 2 days of data. Treatment management counts the minutes of the calendar month.'); ?></p>
                        <table class="table table-sm small mb-1" data-periods="<?php echo attr((string)count($s['periods'])); ?>">
                            <thead><tr><th><?php echo xlt('30 days from'); ?></th><th><?php echo xlt('to'); ?></th><th class="text-right"><?php echo xlt('Days with data'); ?></th><th><?php echo xlt('Codes this month'); ?></th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($s['periods'] as $p) {
                                $row = array_values(array_filter($periods, static fn(array $x): bool => $x['enrollmentId'] === $e->id && $x['periodStart']->format('Y-m-d') === $p['start']->format('Y-m-d')))[0] ?? null; ?>
                                <tr data-period="<?php echo attr($p['start']->format('Y-m-d')); ?>" data-days="<?php echo attr((string)$p['days']); ?>" data-period-codes="<?php echo attr(implode(' + ', $p['codes'])); ?>">
                                    <td><?php echo text(oeFormatShortDate($p['start']->format('Y-m-d'))); ?></td><td><?php echo text(oeFormatShortDate($p['end']->format('Y-m-d'))); ?></td>
                                    <td class="text-right"><?php echo text((string)$p['days']); ?></td>
                                    <td><?php echo $p['codes'] !== [] ? text(implode(' + ', $p['codes'])) : ($month->contains($p['end']) ? xlt('none: fewer than 2 days') : xlt('ends in a later month')); ?></td>
                                    <td><?php if ($row !== null) { ?><form method="post" onsubmit="return confirm(<?php echo xlj('Remove this period? The removal is kept.'); ?>)"><input type="hidden" name="csrf_token_form" value="<?php echo attr($token); ?>">
                                        <input type="hidden" name="action" value="remove_period"><input type="hidden" name="period" value="<?php echo attr((string)$row['id']); ?>">
                                        <button class="btn btn-link btn-sm p-0" type="submit"><?php echo xlt('Remove'); ?></button></form><?php } ?></td>
                                </tr>
                            <?php } ?>
                            <?php if ($s['periods'] === []) { ?><tr><td colspan="5" class="text-muted"><?php echo xlt('No device period this month.'); ?></td></tr><?php } ?>
                            </tbody>
                        </table>
                        <form method="post" class="form-row align-items-end mb-1">
                            <input type="hidden" name="csrf_token_form" value="<?php echo attr($token); ?>">
                            <input type="hidden" name="action" value="period"><input type="hidden" name="enrollment" value="<?php echo attr((string)$e->id); ?>">
                            <div class="form-group col-md-2"><label class="small" for="ps<?php echo attr((string)$e->id); ?>"><?php echo xlt('30 days from'); ?></label><input type="date" class="form-control form-control-sm" id="ps<?php echo attr((string)$e->id); ?>" name="period_start"></div>
                            <div class="form-group col-md-2"><label class="small" for="dd<?php echo attr((string)$e->id); ?>"><?php echo xlt('Days with data'); ?></label><input type="number" min="0" max="30" class="form-control form-control-sm" id="dd<?php echo attr((string)$e->id); ?>" name="days"></div>
                            <div class="form-group col-md-3"><button class="btn btn-sm btn-outline-primary" type="submit"><?php echo xlt('Enter device period'); ?></button></div>
                        </form>
                    <?php } ?>
                    <?php if (count(array_filter($s['routes'])) > 1) { ?>
                        <p class="small text-muted mb-1"><?php echo xlt('These are alternatives: bill one of them for the month.'); ?></p>
                    <?php } ?>
                    <?php if ($e->service === Service::COCM) { ?>
                        <p class="small text-muted mb-1"><?php echo $earlier[$e->id] ?? false ? xlt('A later month of the episode.') : xlt('The first month of an episode (no CoCM time in the 6 months before).'); ?>
                            <?php echo xlt('Full minutes are used: CMS says only that CPT time rules apply and publishes no lower figures.'); ?></p>
                    <?php } ?>
                    <?php if ($e->service === Service::CCM) { ?>
                        <form method="post" class="small mb-1" data-complex="<?php echo ($complex[$e->id] ?? false) ? '1' : '0'; ?>">
                            <input type="hidden" name="csrf_token_form" value="<?php echo attr($token); ?>">
                            <input type="hidden" name="action" value="complex"><input type="hidden" name="enrollment" value="<?php echo attr((string)$e->id); ?>">
                            <?php if ($userId === $e->practitionerId) { ?>
                                <label class="mb-0"><input type="checkbox" name="complex_mdm" value="1" <?php echo ($complex[$e->id] ?? false) ? 'checked' : ''; ?>>
                                    <?php echo xlt('I made moderate or high complexity medical decisions for this patient this month (complex CCM, 99487)'); ?></label>
                                <button class="btn btn-sm btn-link py-0" type="submit"><?php echo xlt('Save'); ?></button>
                            <?php } else { ?>
                                <?php echo ($complex[$e->id] ?? false) ? xlt('The billing practitioner recorded moderate or high complexity decisions this month.') : xlt('Complex CCM needs the billing practitioner to record their moderate or high complexity decisions this month.'); ?>
                            <?php } ?>
                        </form>
                    <?php } ?>
                    <?php if ($e->service === Service::CCM && $s['routes'][Service::STAFF] !== [] && $s['routes'][Service::PRACTITIONER] !== []) { ?>
                        <p class="small text-muted mb-1"><?php echo xlt('Bill one route or the other: 99491 and 99437 cannot go in the same month as 99490 and 99439.'); ?></p>
                    <?php } ?>
                    <?php if ($s['capped']) { ?><p class="small text-muted mb-1"><?php echo xlt('Time beyond 60 minutes is not billed as 99439: CPT allows it at most twice a month (CMS does not state this; check with your payer).'); ?></p><?php } ?>
                    <?php foreach ($s['blockers'] as $b) { ?><p class="small text-danger mb-1" data-blocker="<?php echo attr($b); ?>"><?php echo text(Labels::blocker($b, $e->service, $s['lookBack'])); ?></p><?php } ?>
                    <?php if ($s['stopsThisMonth']) { ?><p class="small mb-1"><?php echo xlt('Stopped this month: it is the last month that can be billed.'); ?></p><?php } ?>

                    <form method="post" class="form-row align-items-end mt-2">
                        <input type="hidden" name="csrf_token_form" value="<?php echo attr($token); ?>">
                        <input type="hidden" name="action" value="log"><input type="hidden" name="enrollment" value="<?php echo attr((string)$e->id); ?>">
                        <div class="form-group col-md-2"><label class="small" for="so<?php echo attr((string)$e->id); ?>"><?php echo xlt('Date'); ?></label><input type="date" class="form-control form-control-sm" id="so<?php echo attr((string)$e->id); ?>" name="served_on" value="<?php echo attr($today->format('Y-m-d')); ?>"></div>
                        <div class="form-group col-md-1"><label class="small" for="mi<?php echo attr((string)$e->id); ?>"><?php echo xlt('Minutes'); ?></label><input type="number" min="1" max="240" class="form-control form-control-sm" id="mi<?php echo attr((string)$e->id); ?>" name="minutes"></div>
                        <div class="form-group col-md-5"><label class="small" for="wd<?php echo attr((string)$e->id); ?>"><?php echo xlt('What was done'); ?></label><input class="form-control form-control-sm" id="wd<?php echo attr((string)$e->id); ?>" name="activity" maxlength="1000"></div>
                        <?php if ($remote) { ?>
                            <div class="form-group col-md-2 form-check"><input class="form-check-input" type="checkbox" name="interactive" value="1" id="lc<?php echo attr((string)$e->id); ?>">
                                <label class="form-check-label small" for="lc<?php echo attr((string)$e->id); ?>"><?php echo xlt('Included a live conversation with the patient or caregiver'); ?></label></div>
                        <?php } ?>
                        <?php if ($userId === $e->practitionerId && !in_array($e->service, [Service::BHI, Service::COCM], true)) { ?>
                            <div class="form-group col-md-2 form-check"><input class="form-check-input" type="checkbox" name="as_practitioner" value="1" id="ap<?php echo attr((string)$e->id); ?>">
                                <label class="form-check-label small" for="ap<?php echo attr((string)$e->id); ?>"><?php echo xlt('I did this myself'); ?></label></div>
                        <?php } ?>
                        <div class="form-group col-md-2"><button class="btn btn-sm btn-primary" type="submit"><?php echo xlt('Log time'); ?></button></div>
                    </form>
                <?php } else { ?>
                    <p class="small text-muted mb-1"><?php echo xlt('Not active this month.'); ?></p>
                <?php } ?>
                <?php if (!$e->consentComplete || $e->consentOn === null || ($e->initiatingNeeded && $e->initiatingVisitOn === null) || in_array(MonthRules::PCM_RENEWAL, $s['blockers'], true)) { ?>
                    <details class="small"><summary><?php echo xlt('Record consent or initiating visit'); ?></summary>
                        <form method="post" class="mt-2"><input type="hidden" name="csrf_token_form" value="<?php echo attr($token); ?>">
                            <input type="hidden" name="action" value="consent"><input type="hidden" name="enrollment" value="<?php echo attr((string)$e->id); ?>">
                            <p class="small text-muted mb-1"><?php echo xlt('To record only an initiating visit, leave the consent date empty.'); ?></p>
                            <?php $consentFields('e' . $e->id); ?><button class="btn btn-sm btn-outline-primary" type="submit"><?php echo xlt('Save'); ?></button></form></details>
                <?php } ?>
                <?php if ($e->stoppedOn === null) { ?>
                    <details class="small"><summary><?php echo xlt('The patient stopped'); ?></summary>
                        <form method="post" class="form-inline mt-2"><input type="hidden" name="csrf_token_form" value="<?php echo attr($token); ?>">
                            <input type="hidden" name="action" value="stop"><input type="hidden" name="enrollment" value="<?php echo attr((string)$e->id); ?>">
                            <input type="date" class="form-control form-control-sm mr-2" name="stopped_on" value="<?php echo attr($today->format('Y-m-d')); ?>" aria-label="<?php echo xla('Stopped on'); ?>">
                            <button class="btn btn-sm btn-outline-danger" type="submit"><?php echo xlt('Stop'); ?></button></form></details>
                <?php } ?>
            </div>
        </div>
    <?php } ?>

    <h2 class="h5"><?php echo xlt('Time logged'); ?> <small class="text-muted"><?php echo text($month->key()); ?></small></h2>
    <table class="table table-sm">
        <thead><tr><th><?php echo xlt('Date'); ?></th><th><?php echo xlt('Service'); ?></th><th class="text-right"><?php echo xlt('Minutes'); ?></th><th><?php echo xlt('By'); ?></th><th><?php echo xlt('What was done'); ?></th><th></th></tr></thead>
        <tbody>
        <?php $byId = [];
        foreach ($list as $e) {
            $byId[$e->id] = $e;
        }
        foreach ($entries as $t) { ?>
            <tr data-entry="<?php echo attr((string)$t['id']); ?>">
                <td><?php echo text(oeFormatShortDate($t['servedOn']->format('Y-m-d'))); ?></td>
                <td><?php echo text(isset($byId[$t['enrollmentId']]) ? strtoupper($byId[$t['enrollmentId']]->service) : ''); ?></td>
                <td class="text-right"><?php echo text((string)$t['minutes']); ?></td>
                <td><?php echo text($t['user'] . ' (' . Labels::role($t['role']) . ')'); ?></td>
                <td><?php echo text($t['activity']); ?><?php if ($t['interactive']) { ?> <span class="badge badge-light border" data-interactive="1"><?php echo xlt('live conversation'); ?></span><?php } ?></td>
                <td><form method="post" onsubmit="return confirm(<?php echo xlj('Remove this entry? The removal is kept.'); ?>)"><input type="hidden" name="csrf_token_form" value="<?php echo attr($token); ?>">
                    <input type="hidden" name="action" value="remove"><input type="hidden" name="entry" value="<?php echo attr((string)$t['id']); ?>">
                    <button class="btn btn-link btn-sm p-0" type="submit"><?php echo xlt('Remove'); ?></button></form></td>
            </tr>
        <?php } ?>
        <?php if ($entries === []) { ?><tr><td colspan="6" class="text-muted"><?php echo xlt('None this month.'); ?></td></tr><?php } ?>
        </tbody>
    </table>

    <h2 class="h5"><?php echo xlt('Enroll'); ?></h2>
    <form method="post">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr($token); ?>"><input type="hidden" name="action" value="enroll">
        <div class="form-row">
            <div class="form-group col-md-4"><label for="service"><?php echo xlt('Service'); ?></label>
                <select class="form-control form-control-sm" id="service" name="service"><option value=""></option>
                    <?php foreach (Service::ALL as $sv) { ?><option value="<?php echo attr($sv); ?>"><?php echo text(Labels::service($sv)); ?></option><?php } ?></select></div>
            <div class="form-group col-md-4"><label for="practitioner"><?php echo xlt('Billing practitioner'); ?></label>
                <select class="form-control form-control-sm" id="practitioner" name="practitioner"><option value=""></option>
                    <?php foreach ($practitioners as $id => $name) { ?><option value="<?php echo attr((string)$id); ?>"><?php echo text($name); ?></option><?php } ?></select></div>
            <div class="form-group col-md-2"><label for="started_on"><?php echo xlt('Start'); ?></label><input type="date" class="form-control form-control-sm" id="started_on" name="started_on" value="<?php echo attr($today->format('Y-m-d')); ?>"></div>
        </div>
        <p class="small mb-1"><?php echo xlt('Conditions managed (from the active problem list; CCM needs two or more chronic conditions expected to last at least 12 months, PCM one high-risk condition expected to last at least 3 months, BHI and CoCM the behavioral health condition; RPM and RTM the condition monitored, acute or chronic):'); ?></p>
        <?php foreach ($problems as $p) { ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="conditions[]" value="<?php echo attr((string)$p['id']); ?>" id="c<?php echo attr((string)$p['id']); ?>">
                <label class="form-check-label small" for="c<?php echo attr((string)$p['id']); ?>"><?php echo text($p['title'] . ($p['code'] !== '' ? ' (' . $p['code'] . ')' : '')); ?></label></div>
        <?php } ?>
        <?php if ($problems === []) { ?><p class="small text-muted"><?php echo xlt('No active problems on the problem list.'); ?></p><?php } ?>
        <p class="small text-muted mb-1"><?php echo xlt('APCM is billed by the number of chronic conditions chosen: G0556 for one or none, G0557 for two or more, G0558 for a Qualified Medicare Beneficiary with two or more.'); ?></p>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="qmb" value="1" id="qmb">
            <label class="form-check-label small" for="qmb"><?php echo xlt('For APCM: the patient is a Qualified Medicare Beneficiary (QMB)'); ?></label></div>
        <div class="form-row"><div class="form-group col-md-4"><label class="small" for="device_kind"><?php echo xlt('For RTM: what it monitors'); ?></label>
            <select class="form-control form-control-sm" id="device_kind" name="device_kind"><option value=""></option>
                <?php foreach (Service::RTM_KINDS as $k) { ?><option value="<?php echo attr($k); ?>"><?php echo text(Labels::deviceKind($k)); ?></option><?php } ?></select></div></div>
        <?php $consentFields('new'); ?>
        <div class="form-group"><label for="note"><?php echo xlt('Note'); ?></label><input class="form-control form-control-sm" id="note" name="note" maxlength="500"></div>
        <button class="btn btn-sm btn-primary" type="submit"><?php echo xlt('Enroll'); ?></button>
    </form>
    <p class="small text-muted mt-3 mb-1"><?php echo text(Service::cptNotice()); ?></p>
</div>
</body>
</html>
