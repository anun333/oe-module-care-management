# Changelog

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
