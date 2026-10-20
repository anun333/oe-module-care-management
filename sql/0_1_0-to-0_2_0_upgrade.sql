--
-- Care Management 0.1.0 to 0.2.0: the QMB flag for APCM's G0558, and the month record where the
-- billing practitioner says they made the decisions complex CCM needs. The same blocks are in
-- install.sql; they are guarded, so running both changes nothing.
--

#IfMissingColumn care_mgmt_enrollment qmb
ALTER TABLE `care_mgmt_enrollment` ADD `qmb` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 for a Qualified Medicare Beneficiary (APCM, G0558)';
#EndIf

#IfNotTable care_mgmt_month
CREATE TABLE `care_mgmt_month` (
    `enrollment_id` BIGINT(20) NOT NULL,
    `pid` BIGINT(20) NOT NULL,
    `month_start` DATE NOT NULL COMMENT 'the first day of the calendar month',
    `complex_mdm` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 when the billing practitioner made moderate or high complexity decisions (complex CCM)',
    `recorded_by` INT(11) NOT NULL COMMENT 'users.id: the billing practitioner',
    `recorded_at` DATETIME NOT NULL,
    PRIMARY KEY (`enrollment_id`, `month_start`),
    KEY `idx_pid` (`pid`)
) ENGINE=InnoDB COMMENT='Care Management: what the billing practitioner records about a month of an enrollment';
#EndIf
