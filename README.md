# Care Management for OpenEMR

Monthly care management for OpenEMR: enroll a patient with the consent CMS requires, log the time clinical staff and the billing practitioner spend, and see each month who meets which code. It covers:
- chronic care management (CCM);
- principal care management (PCM);
- behavioral health integration (BHI).

It bills nothing: staff add the codes on a fee sheet, as for any other service. From 0.3.0 it checks what they billed against the time logged.

> **Status: 0.3.3, a pre-release.** Tested on OpenEMR 8.4.1 with synthetic data only. The codes and rules are as read from CMS's documents on 2026-10-18; check them with your billing staff and payer before billing.

What installing it changes in your database, what it reads and writes, and what it hooks into are listed in [FOOTPRINT.md](FOOTPRINT.md), generated from the source.

## What it does

- **Care Management** (main menu): every enrollment active in a month, with the minutes logged, the codes they meet, and what blocks billing, with a CSV for billing staff.
- **Billed** (on the month list and in the CSV, for those who can see billing): the care management codes on the patient's fee sheets for encounters that month, against the codes the time met. Each month is one of:
  - **billed as met:** within one route's codes and units;
  - **billed beyond the time logged:** more codes or units than any route met, or codes nothing met, with the extra named;
  - **billed, though something blocks billing:** consent, conditions and the other blockers below;
  - **met, not billed:** the month is over, its codes are met with nothing blocking, and no fee sheet has them. While the month runs, "not billed yet".
- **The patient's page** (from the dashboard card):
  - enroll in a service, with the billing practitioner and the conditions managed (from the active problem list);
  - record consent, with the four things the patient must be told, and for BHI and CoCM their permission to consult specialists;
  - for CCM, the billing practitioner records a month's moderate or high complexity decision-making, which complex CCM needs;
  - log time;
  - record that the patient stopped.
- **Dashboard card:** this month's minutes against each enrollment's threshold, and what blocks billing.

## The codes

Time is counted per calendar month and never carries over.

| Service | Clinical staff time (general supervision) | The billing practitioner's own time |
|---|---|---|
| CCM: two or more chronic conditions expected to last at least 12 months | 99490 at 20 minutes, 99439 for each further 20 (at most twice) | 99491 at 30 minutes, 99437 for each further 30 |
| PCM: one high-risk condition expected to last at least 3 months | 99426 at 30 minutes, 99427 for each further 30 | 99424 at 30 minutes, 99425 for each further 30 |
| BHI | 99484 at 20 minutes | — |
| Complex CCM, in a month the billing practitioner records moderate or high complexity decision-making | 99487 at 60 minutes, 99489 for each further 30 (the practitioner's time counts when not used for 99491) | — |
| Psychiatric collaborative care (CoCM): the behavioral health care manager's time | 99492 at 70 minutes in the first month of an episode, 99493 at 60 in a later month, 99494 for each further 30; G2214 at 30 when neither is met | — |
| Advanced primary care management (APCM) | No time: G0556 for one chronic condition or none, G0557 for two or more, G0558 for a Qualified Medicare Beneficiary with two or more, once a month | — |
| APCM add-ons, for the practitioner billing APCM that month (2026) | No time: G0568 with CoCM in an episode's first month, G0569 in a later month, G0570 with general BHI | — |

- **CCM's two routes:** the practitioner's time counts toward the staff codes when it isn't used for 99491. 99491 and 99437 can't be billed in the same month as 99490 and 99439, so when both routes are met the page shows both and says to bill one.
- **Who can log practitioner time:** only the enrollment's billing practitioner, logging their own work. Anyone else's time is clinical staff time.
- **Conservative where CMS is silent.** CMS's booklet and FAQ say "each additional 20 minutes" and give no monthly limit, so each add-on unit needs its full time, and 99439 is limited to two a month (CPT's limit, as most sources cite it). Check both with your payer.
- **Complex CCM is a route of its own,** billed instead of 99490 or 99491 in that month. Only the billing practitioner can record the decision-making: CMS says it "cannot be subcontracted".
- **CoCM's episode** starts with the first month of care manager time. A new one, with a new first month, starts after 6 calendar months without CoCM. CMS says only that "CPT time rules apply" and publishes no lower figures, so full minutes are used. Only the care manager's time is counted: CMS doesn't say the practitioner's own counts.
- **APCM's add-ons** are shown beside a CoCM or BHI enrollment with time logged that month, when the same practitioner bills APCM. Billed outside APCM, the time-based codes keep their time requirements.
- **Alternatives:** when more than one route is met, the page lists them all and says to bill one.

## What blocks billing

- **Consent not recorded, or recorded without all four things CMS requires the patient be told** (for BHI and CoCM, also the patient's permission for the practitioner to consult specialists, a psychiatric consultant included):
  - that the service is available;
  - that cost-sharing applies;
  - that only one practitioner can furnish and bill it in a calendar month;
  - that they can stop, effective at the end of the month.
- **Too few conditions:** two for CCM, one for PCM, BHI and CoCM. APCM's code follows the number of conditions instead.
- **No initiating visit,** when the patient had no visit in the year before the enrollment started. For practices billing under the fee schedule, CMS's booklet says CCM must have been discussed at that visit. Its RHC/FQHC manual says it need not have been.
- **CCM and PCM by the same practitioner** in the same month. A different practitioner may bill PCM for another condition.
- **CoCM and general BHI** for the same patient in the same month, whoever bills them.
- **APCM and CCM or PCM by the same practitioner** in the same month (CY 2025 PFS rule, Table 26). The same table also bars TCM, interprofessional consultations, virtual check-ins and e-visits by that practitioner; the module doesn't track those, so check them on the fee sheet.

A patient who stops can still be billed for the month they stop in, since stopping takes effect at the end of the month.

## Health centers

RHCs and FQHCs bill these individual codes. CMS ended G0511 (billing was allowed "through September 30, 2025") and G0512 ("no longer payable … beginning January 1, 2026"). Since 2026 they may bill APCM and its add-ons too, "with or without a qualifying visit" (APCM FAQ). They're paid separately from the RHC AIR or FQHC PPS rate, at national non-facility fee schedule rates. Part B coinsurance applies, which the consent covers.

## Who can open what

| Page | Check | Same as |
|---|---|---|
| Month list and menu entry | `patients/med` | the Staff Worklist |
| The month list's CSV, and its Billed column | `acct/bill` | OpenEMR's billing pages |
| The patient's page (enroll, consent, log time) | `patients/med` write | the Staff Worklist's actions |
| The Staff Worklist's "Care management, not billed" tab (with the Staff Worklist 0.8.0 or later) | `acct/bill` | the month list's billing columns |
| Dashboard card | `patients/med` | the module's pages |

Every view and every change is written to OpenEMR's audit log. Time entries are never edited: a wrong one is removed, and the removal is kept with who made it and when.

## Not done yet

- **Creating the month's encounter and fee sheet lines.** It writes billing, so it waits until the month list has been used. Until then, the Billed check finds the months nobody billed.
- **Checking for TCM, e-visits and the other codes APCM excludes,** which the module doesn't track.
- **Checking the care plan itself.** CMS requires an electronic care plan; OpenEMR's Care Plan form is where it lives.

## Install

```
composer require anun333/oe-module-care-management
```

Then in OpenEMR, Modules › Manage Modules: Register, Install and Enable **Care Management**.

## Compatibility

**PHP security.** PHP's php2shell advisory (2026-10-21; CVE-2026-109618 and CVE-2026-109619) is remote code execution without logging in, and it is being exploited. Run OpenEMR on PHP 8.2.35, 8.3.36, 8.4.27, 8.5.12 or later, whatever the module needs. The official OpenEMR 7.0.2 and 7.0.3 Docker images can't get that fix: they carry PHP 8.2.16 and 8.3.15 on Alpine releases that no longer get updates. Run 7.0 only on a patched PHP, or upgrade OpenEMR. OpenEMR rebuilt its 8.4.1, 8.3.0, 8.2.0 and 8.0.0.3 images with the fix on 2026-10-22 (forum t/27157); a container started before then needs pulling again.

**Older OpenEMR:** on 7.0.3 and older (no `OEGlobalsBag`), the module stays switched off rather than stop OpenEMR's pages; checked on fresh 7.0.2 and 7.0.3 installs with every module enabled. On fresh official 8.1.0, 8.3.0 and 8.5.0 images (2026-10-23) it installs through Module Manager and its pages that need no patient load, with every module enabled and with it alone (`dev/module_compat_check.sh`). 7.0.4 to 8.0 haven't been tried.

Tested with synthetic data on OpenEMR 8.4.1 (Docker image `openemr/openemr:8.4.1`), with `dev/care_management_check.py` in the openhealthcare repository. That check:
- installs the module through Module Manager;
- enrolls five patients with conditions from their problem lists;
- logs staff and practitioner time across two months;
- confirms the codes by both CCM routes, BHI, and each blocker (no consent, CCM and PCM by one practitioner, no initiating visit);
- stops an enrollment;
- checks that time logged as the practitioner's by someone else becomes staff time;
- produces the month list, its CSV and the card;
- plants fee-sheet lines on synthetic encounters and finds each billing check: billed as met (CCM), billed beyond the time (BHI billed twice), met and not billed yet (October), and met and not billed (September);
- removes entries;
- removes everything.

The same check passes on current master (8.5.0-dev, 1faefbbeb9, 2026-10-18). It uses OpenEMR features present since 8.1.0. It installs and its pages load on 8.1.0 and 8.3.0 (above); the full end-to-end check runs on 8.4.1 and master. It needs PHP 8.3 or later.

## Development

```
composer install
vendor/bin/phpunit
php tools/footprint.php          # regenerate FOOTPRINT.md after changing the module
php tools/footprint.php --check  # what CI runs
```

The unit tests need no database or OpenEMR install. CI runs them, and the footprint check, on PHP 8.3, 8.4 and 8.5.

## Sources

Read 2026-10-18, and for 0.2.0 on 2026-10-19:
- CMS, [Chronic Care Management Services](https://www.cms.gov/files/document/chroniccaremanagement.pdf) (MLN909188, June 2025), and the [CCM FAQ](https://www.cms.gov/files/document/chronic-care-management-faqs.pdf).
- CMS, [Behavioral Health Integration Services](https://www.cms.gov/files/document/mln909432-behavioral-health-integration-services.pdf) (MLN909432, January 2026).
- The CY 2025 and CY 2026 Physician Fee Schedule final rules (89 FR 97710, 90 FR 49266), and the CY 2017 rule for CoCM's episode (81 FR 80241).
- CMS, [Advanced Primary Care Management Services](https://www.cms.gov/medicare/payment/fee-schedules/physician-fee-schedule/advanced-primary-care-management-services) and its [FAQ](https://www.cms.gov/files/document/advanced-primary-care-management-apcm-services-faq.pdf) (January 2026), and the [BHI FAQ](https://www.cms.gov/medicare/medicare-fee-for-service-payment/physicianfeesched/downloads/behavioral-health-integration-faqs.pdf).
- Medicare Benefit Policy Manual chapter 13, §230, and Claims Processing Manual chapter 9, §60.8, for RHCs and FQHCs.

## Other modules for OpenEMR

From the same project. Each installs with `composer require anun333/<module>` and is then enabled in Modules › Manage Modules.

| Module | What it does |
|---|---|
| [Clinic Forms](https://github.com/anun333/oe-module-clinic-forms) | Printable clinic paperwork: consents, privacy notices, referral letters and validated screening questionnaires, with the clinic's own details, in English and Spanish |
| [UDS Report](https://github.com/anun333/oe-module-uds-report) | HRSA's Uniform Data System report for health centers: the patient, visit and diagnosis tables, eCQM readiness, a Table 9D worksheet and grant evidence |
| [Staff Worklist](https://github.com/anun333/oe-module-staff-worklist) | One queue of outstanding staff work: unsigned results, orders with no result, referrals with no reply, screening and claims follow-up |
| [Sliding Fee Discount](https://github.com/anun333/oe-module-sliding-fee) | HRSA's sliding fee discount program: the schedule checked against Chapter 9, income assessments, and the discount applied |
| [Migration](https://github.com/anun333/oe-module-migration) | Records from another EHR, read from the FHIR export certified EHRs offer, checked before import and safe to rerun |
| [Layout Export](https://github.com/anun333/oe-module-layout-export) | Layouts and lists exported to files you can keep in version control, compared with the site or another file; reads the old Backup page's SQL files without running them |

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
