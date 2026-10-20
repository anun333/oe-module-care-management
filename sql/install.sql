--
-- Care Management module tables.
--
-- Enrollments hold the consent CMS requires before billing; time entries are never
-- edited, only removed (and the removal kept), since they back claims.
--

#IfNotTable care_mgmt_enrollment
CREATE TABLE `care_mgmt_enrollment` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `pid` BIGINT(20) NOT NULL,
    `service` VARCHAR(8) NOT NULL COMMENT 'ccm, pcm or bhi',
    `practitioner_id` INT(11) NOT NULL COMMENT 'users.id of the billing practitioner',
    `conditions_json` TEXT NOT NULL COMMENT 'the problems managed: list of {id, title, code}',
    `consent_on` DATE DEFAULT NULL,
    `consent_how` VARCHAR(8) DEFAULT NULL COMMENT 'verbal or written',
    `consent_explained` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 when all four things CMS lists were explained',
    `consent_by` INT(11) DEFAULT NULL COMMENT 'users.id who took it',
    `initiating_needed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'no visit in the year before the start',
    `initiating_visit_on` DATE DEFAULT NULL,
    `started_on` DATE NOT NULL,
    `stopped_on` DATE DEFAULT NULL COMMENT 'the patient stopped; effective at the end of that month',
    `note` TEXT,
    `created_by` INT(11) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pid` (`pid`)
) ENGINE=InnoDB COMMENT='Care Management: enrollment in CCM, PCM or BHI, with its consent';
#EndIf

#IfNotTable care_mgmt_time
CREATE TABLE `care_mgmt_time` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `pid` BIGINT(20) NOT NULL,
    `enrollment_id` BIGINT(20) NOT NULL,
    `served_on` DATE NOT NULL,
    `minutes` INT(11) NOT NULL,
    `role` VARCHAR(16) NOT NULL COMMENT 'staff, or practitioner for time the billing practitioner spends personally',
    `activity` TEXT NOT NULL,
    `user_id` INT(11) NOT NULL COMMENT 'users.id who did the work',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_enrollment_served` (`enrollment_id`, `served_on`),
    KEY `idx_pid` (`pid`)
) ENGINE=InnoDB COMMENT='Care Management: time logged per enrollment';
#EndIf

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
