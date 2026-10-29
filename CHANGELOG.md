# Changelog

## 0.5.0 (2026-10-29)

- **The consent form is a link away.** With Clinic Forms installed and on, a patient's page links to its care management consent, ticked and filled for the patient whose chart is open, from **Enroll** and from **Record consent or initiating visit**. Staff print it in Clinic Forms and record the consent here as before. Without Clinic Forms the link isn't shown.

## 0.4.3 (2026-10-27)

- **Easier to hit on a patient's page:** the time row's "Included a live conversation" and "I did this myself" boxes are now 24 pixels, and **Record consent or initiating visit** and **The patient stopped** open from a taller row; each was under 20 pixels and close to the next control (WCAG 2.2, 2.5.8 Target Size).

## 0.4.2 (2026-10-27)

- **On OpenEMR older than 8.1.0, the module's pages say so instead of showing a blank page.** The module needs 8.1.0 or later and stays switched off below it, but a page opened from a bookmark or a typed address still ran and stopped with an empty HTTP 500. Each page now answers with a short page naming the version the module needs and the site's version (found installing every module on a fresh 7.0.2).

## 0.4.1 (2026-10-26)

- **The Staff Worklist tab is built on openhealthcare-common's shared contract** (1.5.0), so it loads, and is tested, without the Worklist's classes. Staff see the same "Care management, not billed" tab with the same rows, on any Worklist from 0.8.0. The bootstrap also registers the library's contracts under their own prefix, so they load where a module copied in by hand carries an older copy that registered first; if no copy with the contracts is there at all, the tab is left out rather than stop the page.
- Requires openhealthcare-common 1.5.0.
- **The staff guide** explains CoCM's "billable from" minutes (CPT's time rules, which CMS says apply).
- **Install note:** restart the container after adding, upgrading or removing a module copied in by hand (OpenEMR's official 8.0.0 to 8.5.0 images keep class locations, and classes not found, in APCu).

## 0.4.0 (2026-10-26)

Released before the CY 2027 physician fee schedule final rule, which wasn't published on 2026-10-26 (the proposed rule is FR 2026-14327, 91 FR 43842). Everything here stands on 2026's rules; when the final rule is out, its 2027 entry is checked against it in a later release.

- **New: codes and rules by calendar year.** [data/codes.json](data/codes.json) holds each year's codes, minutes, days and the rules that change from year to year (the initiating visit's look-back, PCM's year), each year marked "final" or "proposed", and a year may repeat an earlier one with only its changes. A month is counted under its own year's final rule. A month in a year the module doesn't have yet is counted under the last year it has, and the patient page and the month list say so. 2026 is the only final year.
- **The CY 2027 proposed rule is in the file and never used.** Its only care management changes the module could count are RPM and RTM's: an initiating visit at the start of every RPM or RTM enrollment, by the billing practitioner, face to face, and RTM only for established patients (91 FR 43892). CCM, PCM, BHI, CoCM, APCM and its add-ons get new values only. Proposed values are never used, not even behind a setting: CMS changes proposals in final rules, the module only advises what to bill, and the final rule lands about eight weeks before any 2027 month, so a setting would only ever count a month under rules that might not be final.
- **New: remote physiologic and remote therapeutic monitoring (RPM and RTM)**, as the CY 2026 final rule set them (90 FR 49394-99):
  - device and data for each 30-day period, by its days of data, entered from the device's own system: 2 to 15 days meet 99445 (RTM: 98984, 98985 or 98986 by what it monitors), 16 or more 99454 (98976, 98977, 98978); "not additive" (90 FR 49397). The code goes with the month the period ends;
  - set-up, 99453 or 98975, once an episode, with the first period that has 2 days ("now requires 2 days of monitoring in a 30-day period for reporting, as opposed to 16", 90 FR 49399);
  - treatment management by calendar month, staff and practitioner time: 99470 or 98979 at 10 minutes, 99457 or 98980 at 20, 99458 or 98981 for each further 20. The codes "require a live, interactive communication with the patient/caregiver" (90 FR 49397), so time is marked as one when logged, and a month with none meets no treatment management code and says why;
  - only one RPM or RTM enrollment for a patient in a month ("RPM and RTM may not be billed together"; "only one practitioner can bill" the device codes "during a 30-day period", 88 FR 78883), and device periods can't overlap; either may go with CCM, PCM, BHI or APCM;
  - RPM only for an established patient, a visit with the billing practitioner in the 3 years before (88 FR 78883-84; Claims Processing Manual chapter 12, 30.6.7); RTM needs no initiating visit in 2026;
  - consent with its date and how it was given, without CCM's list of what the patient must be told.
- **New: PCM's yearly initiating visit.** MLN909188 (June 2025, page 10): "You can provide PCM services monthly if the patient needs them. After 1 year, we require another initial visit to continue the services." A PCM month blocks from the thirteenth calendar month after the start, or after the latest initiating visit recorded, until a new one is recorded.
- **An initiating visit can be recorded on its own:** **Record consent** is now **Record consent or initiating visit**, and with the consent date left empty it records only the visit, for PCM's yearly one or one held after consent was taken. It also opens when PCM's year is up.
- **Changed: CoCM's minutes follow CPT time rules, as CMS says.** CMS: "CPT time rules apply" (MLN909432, January 2026, page 7) and "Note: CPT time rules apply to general BHI and CoCM services" (page 13); for G2214, "CPT time rules would apply, consistent with the guidance in the CPT codebook for CPT codes 99492-99494" (CY 2021 PFS final rule, 85 FR 84548). Under "the general CPT rule regarding the midpoint for certain timed services" (Claims Processing Manual chapter 12, 30.6.1.B), a code counts once more than half its time is done. Applied to the times on MLN909432's page 13:
  - 99492, "70 minutes initial calendar month": from **36** minutes (was 70);
  - 99493, "60 minutes per subsequent calendar month": from **31** (was 60);
  - 99494, "Each additional 30 minutes per calendar month", an add-on to 99492 and 99493 (page 8): each unit from **16** minutes past the base code's full time, so the first at 86 minutes in a first month and 76 in a later one (was 100 and 90);
  - G2214, "30 minutes of behavioral health care manager time per calendar month", for when "you don't meet the number of minutes needed to bill using the current coding" (page 8): from **16** (was 30), when 99492's 36 or 99493's 31 isn't met.

  CMS's documents don't print 36, 31 or 16; they are CPT's midpoint rule applied to CMS's times, with each quote in data/codes.json. There is no setting. The patient page shows the month's minutes against the threshold, for example "41 min; billable from 36", and the dashboard card shows minutes against 36 or 31. A month with 36 to 69 minutes in a first month, or 31 to 59 in a later one, was G2214 or nothing and is now 99492 or 99493. General BHI (99484) keeps "at least 20 minutes", as CMS words it.
- **Not yet: the shared library's contracts.** openhealthcare-common 1.5.0, with the Worklist work-source contract, isn't released; the module still requires ^1.4.0 and adopts the contract in a later release.
- **Database:** `care_mgmt_enrollment.device_kind` (what RTM monitors), `care_mgmt_time.interactive` (a live conversation) and the table `care_mgmt_device_period`, added by [sql/0_3_9-to-0_4_0_upgrade.sql](sql/0_3_9-to-0_4_0_upgrade.sql) and install.sql, both guarded.
- **Checked:** the year rules (proposed never used, a final year used for its months and repeating its base, a self-repeating year refused), RPM and RTM's codes, days, minutes, live conversation and blockers, PCM's year, the look-back by the year an enrollment starts, and CoCM's thresholds at each boundary (15/16, 35/36, 85/86, 30/31, 75/76, 115/116) are unit tests; the new ones fail on 0.3.9's code, the CoCM ones fail with the old full-time thresholds, and all pass on PHP 8.2 and 8.5. End to end on OpenEMR 8.4.1 (`dev/care_management_check.py`), on synthetic data: RPM with a period of 16 days and 22 minutes with a live conversation meets 99453 + 99454 + 99457 and is billed as met; an overlapping period and one with more days than it has run are refused; RPM for a patient the billing practitioner never saw is blocked until the visit is recorded on its own; RTM of muscles and joints meets 98975 + 98985, its 12 minutes nothing until a minute with a live conversation gives 98979; RPM and RTM together block both; PCM from 2025-09-10 blocks in September 2026 until a new visit; a 2027 month carries the note; a CoCM first month with 36 minutes is 99492, shown as "36 min; billable from 36", and a later month with 76 minutes is 99493 + 99494. The same check on 0.3.9 passes its earlier steps and stops at the first RPM enrollment, which 0.3.9 refuses.

## 0.3.9 (2026-10-26)

- **Changed: only a visit with the billing practitioner spares an initiating visit, for every service.** MLN909188 asks for one for "patients who you haven't seen within the previous 1 year", addressed to the billing practitioner, and MLN909432 says the BHI initiating visit "establishes the patient's relationship with the billing practitioner". CCM, PCM, BHI and CoCM counted a visit with anyone; they now count only the billing practitioner's, as APCM has since 0.3.8. This is worked out each time a page is read, so a month of an enrollment saved earlier, whose patient was seen only by someone else, now shows **No initiating visit recorded** until one is entered.
- **The consent method shows in the user's language** (Verbal or Written) instead of the stored word.
- **The enrollment and time forms' labels are tied to their fields,** so a screen reader names each one: the consent date, how consent was given, the initiating visit, and the time log's date, minutes and what was done.
- **A missing patient shows its message inside the page,** with its title and language, instead of a bare line of text.
- **Each page declares the signed-in user's OpenEMR language and direction** (openhealthcare-common 1.4.0's `pageLang()`), instead of always English. The call is guarded: where a site's other modules load an older copy of the shared library first, the page says English, as before, rather than stop. The module now requires openhealthcare-common ^1.4.0.
- **The dashboard card's button is checked as a guide label directly** (the shared library now reads Twig's `|xlt`), so composer.json no longer names it.
- **Licences and install notes:** the CC0 text the README points to is shipped in [LICENSES/CC0-1.0.txt](LICENSES/CC0-1.0.txt); the README's compatibility line says PHP 8.2, as 0.3.8 made it; on a manual install, every module's copy of the shared library must be the same version, since the first one loaded serves all.
- **Checked:** the look-back for every service, its on-read rule, the labels and the language guard (with an older library) are unit tests that fail on 0.3.8's code; all pass on PHP 8.2 and 8.5. End to end on OpenEMR 8.4.1 (`dev/care_management_check.py`), on synthetic data: a CCM billed by someone other than the practitioner who saw the patient, and a CoCM, need an initiating visit even when the enrollment stored otherwise; one recorded clears it; a CCM by the practitioner who saw the patient needs none; the consent shows as Verbal; a missing patient gets the page's message.

## 0.3.8 (2026-10-26)

- **Fixed: APCM months for patients last seen one to three years ago were blocked for no initiating visit.** CMS's APCM FAQ: "All new patients and those who have not been seen by the billing practitioner within 3 years require an initiating visit." The module looked back one year, at anyone's visits, as it does for CCM. APCM now looks back 3 years at the billing practitioner's own visits, and works this out on read, so enrollments saved earlier read right too. CCM, PCM, BHI and CoCM keep their one year (MLN909188; the BHI FAQ of 2023-12-05). A patient seen in the last year only by someone other than the APCM practitioner now needs an initiating visit, as the FAQ says.
- **Fixed: a CoCM patient stopped and enrolled again within 6 months got the first month's code (99492) again.** The episode is the patient's (CY 2017 rule, 81 FR 80241): earlier CoCM time now counts under any of the patient's CoCM enrollments, so the new enrollment's month is a later one (99493).
- **The CPT numbers are in one data file,** [data/codes.json](data/codes.json), with the minutes CMS sets and labels in our own words, and the CPT trademark notice. The notice is on the patient page, the month list, the README and the guide.
- **PHP 8.2:** the module asked for PHP 8.3 though it runs on 8.2, which OpenEMR 8.1.0 still supports. It now asks for 8.2, and CI runs on 8.2 to 8.5.

## 0.3.7 (2026-10-26)

- **A staff guide:** [GUIDE.md](GUIDE.md) walks through each task by role, with the labels as the pages show them. **Help** on every page opens it at this version's tag, in a new window.
- **The guide's labels are checked:** each of the 38 labels GUIDE.md quotes in bold is a string the pages show, or the dashboard card's button, named in composer.json with the template it comes from, so a renamed button fails the tests.

## 0.3.6 (2026-10-26)

- **Text and buttons are easier to read on this module's pages.** OpenEMR's blue (#007bff) is 3.97:1 on white, under the 4.5:1 that WCAG 2.1 AA asks of normal text and that HHS's Section 504 rule requires from 2027-05-11. Under OpenEMR's light and manila themes, the module's links and primary buttons now use #0056b3 (7.0:1), and muted and warning text darker shades that stay readable on tinted rows, and a link inside text is underlined; the dark, solar and colour themes keep their own colours, and OpenEMR's own pages are unchanged.

## 0.3.5 (2026-10-25)

- **Fixed: on OpenEMR 7.0.4 and 8.0.0 to 8.0.0.3, enabling this module stopped every OpenEMR page, the login page included (HTTP 500).** Those releases have `OEGlobalsBag` without the getters its bootstrap called, which came with 8.1.0. The module needs 8.1.0 or later (its pages use 8.1's session and service container), so on an older release it now stays switched off, as it already did before 7.0.4. Its pages ask openhealthcare-common 1.3.3, now required at runtime, for the web root and OpenEMR's folders. Checked on fresh installs of the official 7.0.2, 7.0.4 and 8.0.0 images with every module enabled, and on 8.4.1 and master.

## 0.3.4 (2026-10-23)

- Development: the test for translated strings with an apostrophe or quote mark uses [openhealthcare-common](https://github.com/anun333/openhealthcare-common)'s scan, the same one every openhealthcare module runs. It is a development dependency only; nothing installed changes.
- **Accessibility:** both pages declare their language (`<html lang>`).
- **README:** run OpenEMR on a PHP with PHP's php2shell fix (2026-10-21: 8.2.35, 8.3.36, 8.4.27, 8.5.12 or later). The official OpenEMR 7.0.2 and 7.0.3 Docker images can't get it.
- **FOOTPRINT.md** now comes from openhealthcare-common's footprint tool (1.1), the one every module shares; `tools/footprint.php` only loads it. The sheet's closing notes, what it can't show, are this module's own (in composer.json): the old ones were the Staff Worklist's, and said this module's permission checks had been verified end to end with restricted users, which they haven't.
- **Checked:** unit tests; the nightly matrix on 8.4.1 and master (35 checks each); fresh official 8.1.0, 8.3.0 and 8.5.0 images, with every module enabled and with it alone (on 7.0.2 and 7.0.3 it stays switched off, as designed).

## 0.3.3 (2026-10-22)

- **No longer stops an older OpenEMR.** OpenEMR loads a module's bootstrap on every request, the login page included. This module's used `OEGlobalsBag`, which came with OpenEMR 7.0.4 and 8.0, so on 7.0.3 or older, enabling it made every page fail (HTTP 500), login included, until the module was switched off in the database. It now stays switched off there instead. Found by the nightly matrix's new fresh 7.0.2 install (`dev/module_compat_check.sh`).

## 0.3.2 (2026-10-22)

- **A Staff Worklist tab: "Care management, not billed."** With the Staff Worklist installed (0.8.0 or later), this module adds the tab through the worklist's `staff_worklist.collect_sources` event: an enrollment whose month, in the last three completed months, met a code with nothing billed for its service. It's added for users who may see billing (`acct/bill`), as the month list requires. Each row is one enrollment and month, owned by the billing practitioner, and opens the month list. Checked in the end-to-end check on 8.4.1 and master (35 checks): 9301's September, met and not billed, is listed; a billed month and one still running aren't.
- **The month's lines as a class** (`Repository\MonthLines`): each active enrollment's minutes, codes met, what was billed and the billing check, which the month list used to work out in its page. The month list and the Staff Worklist's new care management tab both read it, so they agree. Nothing on the month list changes; its end-to-end check is unchanged (33 checks on OpenEMR 8.4.1).

## 0.3.1 (2026-10-20)

- **Fixed:** the month list's note about the Billed column read "the patient`s fee sheets". OpenEMR's `xl()` turns an apostrophe into a backtick, so the note is reworded, and a test now fails on any translated string with an apostrophe or quote mark.

## 0.3.0 (2026-10-20)

- **Billed: what was billed, against what was met.** The month list and its CSV compare the care management codes on each patient's fee sheets, for encounters dated in the month, with the codes the time logged meets. Routes are alternatives, so what was billed is held to the closest one. A month is:
  - **billed as met**;
  - **billed beyond the time logged**, with the extra codes or units named;
  - **billed, though something blocks billing**;
  - **met, not billed** once the month is over, or **not billed yet** while it runs.

  It only reads `billing` and `form_encounter`; it bills nothing. The column and the CSV need `acct/bill`, as the CSV always did.
- `tools/footprint.php` answers a web request with a 404: Composer installs the package under OpenEMR's web root.

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
