<?php

/**
 * Care Management: every enrollment active in a month, with its minutes, the codes they
 * meet and what blocks billing. ?format=csv downloads the same rows for billing staff.
 * It bills nothing: staff add the codes on a fee sheet.
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

// Many patients' care on one page: the same check as the Staff Worklist.
if (!AclMain::aclCheckCore('patients', 'med')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for patients/med: Care Management', xl('Care Management'));
}
$session = SessionWrapperFactory::getInstance()->getActiveSession();
$month = Month::parse((string)($_GET['month'] ?? '')) ?? Month::of(new DateTimeImmutable('today'));
$csv = ($_GET['format'] ?? '') === 'csv';
if ($csv && !AclMain::aclCheckCore('acct', 'bill')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for acct/bill: Care Management billing list', xl('Care Management'));
}
$repo = new EnrollmentRepository();
$active = $repo->activeIn($month);
$pids = array_values(array_unique(array_map(static fn($e): int => $e->pid, $active)));
$entries = (new TimeRepository())->inMonth($month, $pids);
$practitioners = $repo->practitioners();
$patients = [];
if ($pids !== []) {
    $rows = QueryUtils::fetchRecords('SELECT `pid`, `fname`, `lname`, `pubpid`, `DOB` FROM `patient_data` WHERE `pid` IN (' . implode(', ', array_fill(0, count($pids), '?')) . ')', $pids);
    foreach ($rows as $r) {
        $patients[(int)$r['pid']] = $r;
    }
}
$byPatient = [];
foreach ($active as $e) {
    $byPatient[$e->pid][] = $e;
}
$lines = [];
foreach ($active as $e) {
    $s = MonthRules::summarize($e, $entries, $byPatient[$e->pid], $month);
    $lines[] = ['e' => $e, 's' => $s, 'billable' => $s['blockers'] === [] && ($s['routes'][Service::STAFF] !== [] || ($s['routes'][Service::PRACTITIONER] ?? []) !== [])];
}
EventAuditLogger::getInstance()->newEvent('patient-record-select', (string)$session->get('authUser'), (string)$session->get('authProvider'), 1,
    'Care Management month list ' . $month->key() . ($csv ? ' (CSV)' : '') . ', pids: ' . implode(',', $pids));
$name = static fn(int $pid): string => isset($patients[$pid]) ? trim($patients[$pid]['lname'] . ', ' . $patients[$pid]['fname'], ', ') : '#' . $pid;

if ($csv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="care-management-' . $month->key() . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        exit;
    }
    fputcsv($out, ['Patient', 'ID', 'Service', 'Billing practitioner', 'Staff minutes', 'Practitioner minutes', 'Staff route codes', 'Practitioner route codes', 'Billable', 'Blocked by'], ',', '"', '');
    foreach ($lines as $l) {
        $e = $l['e'];
        $s = $l['s'];
        fputcsv($out, [$name($e->pid), (string)($patients[$e->pid]['pubpid'] ?? ''), strtoupper($e->service), $practitioners[$e->practitionerId] ?? '',
            $s['staff'], $s['practitioner'], Labels::codes($s['routes'][Service::STAFF]), Labels::codes($s['routes'][Service::PRACTITIONER] ?? []),
            $l['billable'] ? 'yes' : 'no', implode('; ', array_map(Labels::blocker(...), $s['blockers']))], ',', '"', '');
    }
    fclose($out);
    exit;
}
$moduleUrl = OEGlobalsBag::getInstance()->getWebRoot() . Bootstrap::MODULE_INSTALLATION_PATH . '/public/';
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Care Management'); ?></title>
    <?php Header::setupHeader(); ?>
</head>
<body class="body_top">
<div class="container-fluid py-3">
    <h1 class="h4"><?php echo xlt('Care Management'); ?>: <?php echo text($month->key()); ?></h1>
    <p class="small text-muted"><?php echo xlt('Minutes are counted per calendar month and never carry over. Each add-on needs its full time. Health centers bill these codes themselves, paid outside the PPS rate. This list bills nothing: add the codes on a fee sheet.'); ?></p>
    <form method="get" class="form-inline mb-3">
        <label class="mr-2" for="month"><?php echo xlt('Month'); ?></label>
        <input type="month" class="form-control form-control-sm mr-2" id="month" name="month" value="<?php echo attr($month->key()); ?>">
        <button class="btn btn-sm btn-primary mr-2" type="submit"><?php echo xlt('Show'); ?></button>
        <?php if (AclMain::aclCheckCore('acct', 'bill')) { ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo attr($moduleUrl . 'index.php?month=' . urlencode($month->key()) . '&format=csv'); ?>"><?php echo xlt('Download CSV'); ?></a>
        <?php } ?>
    </form>
    <table class="table table-sm">
        <thead><tr><th><?php echo xlt('Patient'); ?></th><th><?php echo xlt('Service'); ?></th><th><?php echo xlt('Billing practitioner'); ?></th>
            <th class="text-right"><?php echo xlt('Staff minutes'); ?></th><th class="text-right"><?php echo xlt('Practitioner minutes'); ?></th><th><?php echo xlt('Codes met'); ?></th><th><?php echo xlt('Blocked by'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l) {
            $e = $l['e'];
            $s = $l['s']; ?>
            <tr data-enrollment="<?php echo attr((string)$e->id); ?>" data-billable="<?php echo $l['billable'] ? '1' : '0'; ?>">
                <td><a href="<?php echo attr($moduleUrl . 'patient.php?pid=' . $e->pid . '&month=' . urlencode($month->key())); ?>"><?php echo text($name($e->pid)); ?></a></td>
                <td><?php echo text(strtoupper($e->service)); ?><?php if ($s['stopsThisMonth']) { ?> <span class="badge badge-light border"><?php echo xlt('last month'); ?></span><?php } ?></td>
                <td><?php echo text($practitioners[$e->practitionerId] ?? ''); ?></td>
                <td class="text-right"><?php echo text((string)$s['staff']); ?></td>
                <td class="text-right"><?php echo text((string)$s['practitioner']); ?></td>
                <td data-codes="<?php echo attr(Labels::codes($s['routes'][Service::STAFF])); ?>">
                    <?php echo text(Labels::codes($s['routes'][Service::STAFF]) ?: '—'); ?>
                    <?php if (($s['routes'][Service::PRACTITIONER] ?? []) !== []) { ?><br><span class="small"><?php echo xlt('or'); ?> <?php echo text(Labels::codes($s['routes'][Service::PRACTITIONER])); ?></span><?php } ?>
                </td>
                <td class="small text-danger"><?php echo text(implode('; ', array_map(Labels::blocker(...), $s['blockers']))); ?></td>
            </tr>
        <?php } ?>
        <?php if ($lines === []) { ?><tr><td colspan="7" class="text-muted"><?php echo xlt('No enrollments active this month.'); ?></td></tr><?php } ?>
        </tbody>
    </table>
</div>
</body>
</html>
