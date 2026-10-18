<?php

/**
 * Care Management for one patient: enroll in CCM, PCM or BHI with the consent CMS
 * requires, log time, and see this month's minutes, the codes they meet and what
 * blocks billing.
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
use OpenEMR\Core\OEGlobalsBag;
use OpenHealthcare\CareManagement\Bootstrap;
use OpenHealthcare\CareManagement\Domain\Month;
use OpenHealthcare\CareManagement\Domain\MonthRules;
use OpenHealthcare\CareManagement\Domain\Service;
use OpenHealthcare\CareManagement\Labels;
use OpenHealthcare\CareManagement\Repository\EnrollmentRepository;
use OpenHealthcare\CareManagement\Repository\TimeRepository;

// Logging care is clinical documentation: the same check as the Staff Worklist's actions.
if (!AclMain::aclCheckCore('patients', 'med', '', 'write')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for patients/med write: Care Management', xl('Care Management'));
}
$session = SessionWrapperFactory::getInstance()->getActiveSession();
$userId = (int)$session->get('authUserID');
$pid = (int)($_GET['pid'] ?? $session->get('pid') ?? 0);
$patient = QueryUtils::fetchRecords('SELECT `pid`, `fname`, `lname`, `pubpid` FROM `patient_data` WHERE `pid` = ?', [$pid])[0] ?? null;
if ($patient === null) {
    die(xlt('No such patient.'));
}
$enrollments = new EnrollmentRepository();
$times = new TimeRepository();
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
    $explained = !empty($_POST['told_available']) && !empty($_POST['told_cost']) && !empty($_POST['told_one']) && !empty($_POST['told_stop']);
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
        if ($errors === []) {
            $id = $enrollments->add($pid, $service, $practitioner, $conditions, $consentOn, $consentHow, $consentOn !== null && $explained,
                $date($_POST['initiating_visit_on'] ?? null), (string)$started, trim((string)($_POST['note'] ?? '')), $userId);
            $audit('patient-record-update', "enrollment $id ($service)");
            $done = xl('Enrolled.');
        }
    } elseif ($action === 'consent' && isset($mine[(int)($_POST['enrollment'] ?? 0)])) {
        if ($consentOn === null || $consentHow === null) {
            $errors[] = xl('Enter the consent date and whether it was written or verbal.');
        } else {
            $id = (int)$_POST['enrollment'];
            $enrollments->recordConsent($id, $pid, $consentOn, $consentHow, $explained, $date($_POST['initiating_visit_on'] ?? null), $userId);
            $audit('patient-record-update', "consent for enrollment $id");
            $done = xl('Consent recorded.');
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
        if ($served === null || new DateTimeImmutable($served) > $today || !$e->activeIn(Month::of(new DateTimeImmutable($served)))) {
            $errors[] = xl('The date must be today or earlier, in a month the enrollment is active.');
        } elseif ($minutes < 1 || $minutes > 240 || $activity === '') {
            $errors[] = xl('Enter the minutes (1 to 240) and what was done.');
        } else {
            $id = $times->add($pid, $e->id, $served, $minutes, $role, $activity, $userId);
            $audit('patient-record-update', "time entry $id ($minutes minutes, $role)");
            $done = xl('Time logged.');
            $month = Month::of(new DateTimeImmutable($served));
        }
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
$moduleUrl = OEGlobalsBag::getInstance()->getWebRoot() . Bootstrap::MODULE_INSTALLATION_PATH . '/public/';
$token = CsrfUtils::collectCsrfToken(session: $session);
$consentFields = static function (string $k) {
    ?>
    <div class="form-row">
        <div class="form-group col-md-3"><label><?php echo xlt('Consent date'); ?></label><input type="date" class="form-control form-control-sm" name="consent_on"></div>
        <div class="form-group col-md-3"><label><?php echo xlt('Consent was'); ?></label>
            <select class="form-control form-control-sm" name="consent_how"><option value=""></option><option value="verbal"><?php echo xlt('Verbal'); ?></option><option value="written"><?php echo xlt('Written'); ?></option></select></div>
        <div class="form-group col-md-3"><label><?php echo xlt('Initiating visit, if needed'); ?></label><input type="date" class="form-control form-control-sm" name="initiating_visit_on"></div>
    </div>
    <p class="small mb-1"><?php echo xlt('The patient was told (CMS requires all four):'); ?></p>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="told_available" value="1" id="ta<?php echo attr($k); ?>"><label class="form-check-label small" for="ta<?php echo attr($k); ?>"><?php echo xlt('that the service is available to them'); ?></label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="told_cost" value="1" id="tc<?php echo attr($k); ?>"><label class="form-check-label small" for="tc<?php echo attr($k); ?>"><?php echo xlt('that cost-sharing (coinsurance) applies'); ?></label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="told_one" value="1" id="to<?php echo attr($k); ?>"><label class="form-check-label small" for="to<?php echo attr($k); ?>"><?php echo xlt('that only one practitioner can furnish and bill it in a calendar month'); ?></label></div>
    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="told_stop" value="1" id="ts<?php echo attr($k); ?>"><label class="form-check-label small" for="ts<?php echo attr($k); ?>"><?php echo xlt('that they can stop at any time, effective at the end of the month'); ?></label></div>
    <?php
};
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Care Management'); ?></title>
    <?php Header::setupHeader(); ?>
</head>
<body class="body_top">
<div class="container-fluid py-3">
    <h1 class="h4"><?php echo xlt('Care Management'); ?>: <?php echo text(trim($patient['lname'] . ', ' . $patient['fname'], ', ')); ?>
        <small class="text-muted"><?php echo xlt('ID'); ?> <?php echo text((string)$patient['pubpid']); ?></small></h1>
    <?php if ($done !== '') { ?><div class="alert alert-success py-2"><?php echo text($done); ?></div><?php } ?>
    <?php foreach ($errors as $e) { ?><div class="alert alert-danger py-2"><?php echo text($e); ?></div><?php } ?>

    <form method="get" class="form-inline mb-2">
        <input type="hidden" name="pid" value="<?php echo attr((string)$pid); ?>">
        <label class="mr-2" for="month"><?php echo xlt('Month'); ?></label>
        <input type="month" class="form-control form-control-sm mr-2" id="month" name="month" value="<?php echo attr($month->key()); ?>">
        <button class="btn btn-sm btn-outline-secondary" type="submit"><?php echo xlt('Show'); ?></button>
        <a class="ml-3 small" href="<?php echo attr($moduleUrl . 'index.php?month=' . urlencode($month->key())); ?>"><?php echo xlt('All patients this month'); ?></a>
    </form>

    <?php foreach ($list as $e) {
        $d = $details[$e->id] ?? ['consentHow' => '', 'note' => '', 'conditions' => []];
        $active = $e->activeIn($month);
        $s = MonthRules::summarize($e, $entries, $list, $month); ?>
        <div class="card mb-3" data-enrollment="<?php echo attr((string)$e->id); ?>" data-service="<?php echo attr($e->service); ?>">
            <div class="card-body py-2">
                <h2 class="h6 mb-1"><?php echo text(Labels::service($e->service)); ?>
                    <small class="text-muted"><?php echo text($practitioners[$e->practitionerId] ?? ('#' . $e->practitionerId)); ?> ·
                        <?php echo xlt('since'); ?> <?php echo text(oeFormatShortDate($e->startedOn->format('Y-m-d'))); ?>
                        <?php if ($e->stoppedOn !== null) { ?> · <?php echo xlt('stopped'); ?> <?php echo text(oeFormatShortDate($e->stoppedOn->format('Y-m-d'))); ?><?php } ?></small></h2>
                <p class="small mb-1"><?php echo xlt('Conditions'); ?>: <?php echo text(implode('; ', array_map(static fn(array $c): string => $c['title'] . ($c['code'] !== '' ? ' (' . $c['code'] . ')' : ''), $d['conditions'])) ?: xl('none chosen')); ?></p>
                <p class="small mb-1"><?php echo xlt('Consent'); ?>:
                    <?php echo $e->consentOn === null ? xlt('not recorded') : text(oeFormatShortDate($e->consentOn->format('Y-m-d')) . ', ' . $d['consentHow'] . ($e->consentComplete ? '' : ' (' . xl('not all four things recorded as told') . ')')); ?></p>
                <?php if ($active) { ?>
                    <p class="mb-1" data-month-minutes="<?php echo attr((string)($s['staff'] + $s['practitioner'])); ?>">
                        <?php echo text(sprintf(xl('%1$s: %2$s minutes by clinical staff, %3$s by the billing practitioner.'), $month->key(), $s['staff'], $s['practitioner'])); ?></p>
                    <?php foreach ($s['routes'] as $route => $codes) { ?>
                        <p class="small mb-1" data-route="<?php echo attr($route); ?>" data-codes="<?php echo attr(Labels::codes($codes)); ?>">
                            <?php echo text(Labels::role($route)); ?>:
                            <?php echo $codes === [] ? text(sprintf(xl('%1$s more minutes for %2$s'), $s['next'][$route], Service::routes($e->service)[$route]['base'])) : '<strong>' . text(Labels::codes($codes)) . '</strong>'; ?></p>
                    <?php } ?>
                    <?php if ($e->service === Service::CCM && $s['routes'][Service::STAFF] !== [] && $s['routes'][Service::PRACTITIONER] !== []) { ?>
                        <p class="small text-muted mb-1"><?php echo xlt('Bill one route or the other: 99491 and 99437 cannot go in the same month as 99490 and 99439.'); ?></p>
                    <?php } ?>
                    <?php if ($s['capped']) { ?><p class="small text-muted mb-1"><?php echo xlt('Time beyond 60 minutes is not billed as 99439: CPT allows it at most twice a month (CMS does not state this; check with your payer).'); ?></p><?php } ?>
                    <?php foreach ($s['blockers'] as $b) { ?><p class="small text-danger mb-1" data-blocker="<?php echo attr($b); ?>"><?php echo text(Labels::blocker($b)); ?></p><?php } ?>
                    <?php if ($s['stopsThisMonth']) { ?><p class="small mb-1"><?php echo xlt('Stopped this month: it is the last month that can be billed.'); ?></p><?php } ?>

                    <form method="post" class="form-row align-items-end mt-2">
                        <input type="hidden" name="csrf_token_form" value="<?php echo attr($token); ?>">
                        <input type="hidden" name="action" value="log"><input type="hidden" name="enrollment" value="<?php echo attr((string)$e->id); ?>">
                        <div class="form-group col-md-2"><label class="small"><?php echo xlt('Date'); ?></label><input type="date" class="form-control form-control-sm" name="served_on" value="<?php echo attr($today->format('Y-m-d')); ?>"></div>
                        <div class="form-group col-md-1"><label class="small"><?php echo xlt('Minutes'); ?></label><input type="number" min="1" max="240" class="form-control form-control-sm" name="minutes"></div>
                        <div class="form-group col-md-5"><label class="small"><?php echo xlt('What was done'); ?></label><input class="form-control form-control-sm" name="activity" maxlength="1000"></div>
                        <?php if ($userId === $e->practitionerId && $e->service !== Service::BHI) { ?>
                            <div class="form-group col-md-2 form-check"><input class="form-check-input" type="checkbox" name="as_practitioner" value="1" id="ap<?php echo attr((string)$e->id); ?>">
                                <label class="form-check-label small" for="ap<?php echo attr((string)$e->id); ?>"><?php echo xlt('I did this myself'); ?></label></div>
                        <?php } ?>
                        <div class="form-group col-md-2"><button class="btn btn-sm btn-primary" type="submit"><?php echo xlt('Log time'); ?></button></div>
                    </form>
                <?php } else { ?>
                    <p class="small text-muted mb-1"><?php echo xlt('Not active this month.'); ?></p>
                <?php } ?>
                <?php if (!$e->consentComplete || $e->consentOn === null || ($e->initiatingNeeded && $e->initiatingVisitOn === null)) { ?>
                    <details class="small"><summary><?php echo xlt('Record consent'); ?></summary>
                        <form method="post" class="mt-2"><input type="hidden" name="csrf_token_form" value="<?php echo attr($token); ?>">
                            <input type="hidden" name="action" value="consent"><input type="hidden" name="enrollment" value="<?php echo attr((string)$e->id); ?>">
                            <?php $consentFields('e' . $e->id); ?><button class="btn btn-sm btn-outline-primary" type="submit"><?php echo xlt('Save consent'); ?></button></form></details>
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
                <td><?php echo text($t['activity']); ?></td>
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
        <p class="small mb-1"><?php echo xlt('Conditions managed (from the active problem list; CCM needs two or more chronic conditions expected to last at least 12 months, PCM one high-risk condition expected to last at least 3 months):'); ?></p>
        <?php foreach ($problems as $p) { ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="conditions[]" value="<?php echo attr((string)$p['id']); ?>" id="c<?php echo attr((string)$p['id']); ?>">
                <label class="form-check-label small" for="c<?php echo attr((string)$p['id']); ?>"><?php echo text($p['title'] . ($p['code'] !== '' ? ' (' . $p['code'] . ')' : '')); ?></label></div>
        <?php } ?>
        <?php if ($problems === []) { ?><p class="small text-muted"><?php echo xlt('No active problems on the problem list.'); ?></p><?php } ?>
        <?php $consentFields('new'); ?>
        <div class="form-group"><label for="note"><?php echo xlt('Note'); ?></label><input class="form-control form-control-sm" id="note" name="note" maxlength="500"></div>
        <button class="btn btn-sm btn-primary" type="submit"><?php echo xlt('Enroll'); ?></button>
    </form>
</div>
</body>
</html>
