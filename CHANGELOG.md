# Changelog

## 0.2.0 (2026-10-19)

- **Complex CCM (99487, 99489).** In a month the billing practitioner records their moderate or high complexity decision-making, the same time as 99490 meets 99487 at 60 minutes and 99489 for each further 30. The practitioner's time counts when not used for 99491.
  - It's a route of its own, billed instead of the others.
  - Only the billing practitioner can record the decision-making (MLN909188: it "cannot be subcontracted").
- **Psychiatric collaborative care (CoCM).** The behavioral health care manager's time meets:
  - 99492 at 70 minutes in the first month of an episode;
  - 99493 at 60 in a later month;
  - 99494 for each further 30;
  - G2214 at 30 when neither is met.

  A new episode starts after 6 calendar months without CoCM. CMS says only that "CPT time rules apply" and publishes no lower figures, so full minutes are used, and only the care manager's time counts. Consent also needs the patient's permission to consult specialists, a psychiatric consultant included, which now applies to BHI too.
- **Advanced primary care management (APCM).** No time. G0556 for one chronic condition or none, G0557 for two or more, G0558 for a Qualified Medicare Beneficiary with two or more, from the conditions chosen and a new QMB tick.
  - The 2026 add-ons G0568 (CoCM, first month), G0569 (CoCM, later month) and G0570 (general BHI) are shown on a CoCM or BHI enrollment with time logged, when the same practitioner bills APCM that month.
- **New blockers:**
  - CoCM and general BHI for the same patient in a month;
  - APCM and CCM or PCM by the same practitioner in a month.
- **The month list, its CSV and the dashboard card** show every route met; the CSV gains an "Other codes" column. The card shows APCM's code, with no time requirement.
- **Database:** `care_mgmt_enrollment.qmb`, and a `care_mgmt_month` table for the practitioner's monthly record. Both are added by `install.sql` and the 0.1.0 to 0.2.0 upgrade.
- **Tested:**
  - unit tests for complex CCM's thresholds and route, CoCM's codes by month of the episode, APCM's codes, the add-ons, and both new blockers;
  - on OpenEMR 8.4.1, the end-to-end check, now 28 checks: complex CCM recorded only by its practitioner, a CoCM episode's first and later months, the specialists' consent, APCM with QMB on the card and in the CSV, an add-on, and both blockers.

## 0.1.0 (2026-10-18)

First release, as a standalone Composer package.

- **Services:** chronic care management, principal care management and behavioral health integration.
- **Enrollment:** the billing practitioner, the conditions from the active problem list, and consent with the four things CMS requires the patient be told. It also covers the initiating visit when the patient had none in the year before, and stopping (effective at the end of the month).
- **Time log:** clinical staff time and the billing practitioner's own time. Only that practitioner can log the latter. Entries are removed rather than edited, and removals are kept.
- **Month list:** minutes, the codes they meet by each route, and what blocks billing, with a CSV for billing staff.
  - **CCM:** 99490/99439, or 99491/99437.
  - **PCM:** 99426/99427, or 99424/99425.
  - **BHI:** 99484.
  - **Where CMS is silent:** each add-on needs its full time, and 99439 is at most twice a month.
- **A dashboard card** with this month's minutes.
- **Access:** each page makes the same check as the OpenEMR page it stands beside. Every view and change is audit-logged.
- **Tested:**
  - **Isolated tests:** 20; four deliberate breaks of the rules each fail them.
  - **On OpenEMR 8.4.1:** `dev/care_management_check.py`, 17 checks, from installing through Module Manager to removing entries.
