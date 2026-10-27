--
-- Care Management 0.3.9 to 0.4.0: remote monitoring. What RTM monitors, which picks its device codes; whether
-- logged time included a live conversation with the patient, which RPM and RTM treatment management need; and the
-- device periods with their days of data. The same blocks are in install.sql; they are guarded, so running both
-- changes nothing.
--

#IfMissingColumn care_mgmt_enrollment device_kind
ALTER TABLE `care_mgmt_enrollment` ADD `device_kind` VARCHAR(16) DEFAULT NULL COMMENT 'what RTM monitors: respiratory, musculoskeletal or cbt';
#EndIf

#IfMissingColumn care_mgmt_time interactive
ALTER TABLE `care_mgmt_time` ADD `interactive` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 when the time included a live conversation with the patient or caregiver (RPM and RTM)';
#EndIf

#IfNotTable care_mgmt_device_period
CREATE TABLE `care_mgmt_device_period` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `pid` BIGINT(20) NOT NULL,
    `enrollment_id` BIGINT(20) NOT NULL,
    `period_start` DATE NOT NULL COMMENT 'the first of the 30 days',
    `days` INT(11) NOT NULL COMMENT 'days in the 30 on which the device sent data',
    `user_id` INT(11) NOT NULL COMMENT 'users.id who entered it',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_pid` (`pid`),
    KEY `idx_enrollment` (`enrollment_id`, `period_start`)
) ENGINE=InnoDB COMMENT='Care Management: RPM and RTM device periods with their days of data';
#EndIf
