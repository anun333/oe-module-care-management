# Care Management for OpenEMR

Monthly care management for OpenEMR: enroll a patient with the consent CMS requires, log the time clinical staff and the billing practitioner spend, and see each month who meets which code. It covers:
- chronic care management (CCM);
- principal care management (PCM);
- behavioral health integration (BHI).

It bills nothing: staff add the codes on a fee sheet, as for any other service.

> **Status: 0.1.0, a pre-release.** Tested on OpenEMR 8.4.1 with synthetic data only. The codes and rules are as read from CMS's documents on 2026-10-18; check them with your billing staff and payer before billing.

What installing it changes in your database, what it reads and writes, and what it hooks into are listed in [FOOTPRINT.md](FOOTPRINT.md), generated from the source.

## What it does

- **Care Management** (main menu): every enrollment active in a month, with the minutes logged, the codes they meet, and what blocks billing, with a CSV for billing staff.
- **The patient's page** (from the dashboard card):
  - enroll in a service, with the billing practitioner and the conditions managed (from the active problem list);
  - record consent, with the four things the patient must be told;
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

- **CCM's two routes:** the practitioner's time counts toward the staff codes when it isn't used for 99491. 99491 and 99437 can't be billed in the same month as 99490 and 99439, so when both routes are met the page shows both and says to bill one.
- **Who can log practitioner time:** only the enrollment's billing practitioner, logging their own work. Anyone else's time is clinical staff time.
- **Conservative where CMS is silent.** CMS's booklet and FAQ say "each additional 20 minutes" and give no monthly limit, so each add-on unit needs its full time, and 99439 is limited to two a month (CPT's limit, as most sources cite it). Check both with your payer.
- **Not covered:** complex CCM (99487, 99489), psychiatric collaborative care (99492 to 99494, G2214) and advanced primary care management (G0556 to G0558).

## What blocks billing

- **Consent not recorded, or recorded without all four things CMS requires the patient be told:**
  - that the service is available;
  - that cost-sharing applies;
  - that only one practitioner can furnish and bill it in a calendar month;
  - that they can stop, effective at the end of the month.
- **Too few conditions:** two for CCM, one for PCM and BHI.
- **No initiating visit,** when the patient had no visit in the year before the enrollment started. For practices billing under the fee schedule, CMS's booklet says CCM must have been discussed at that visit. Its RHC/FQHC manual says it need not have been.
- **CCM and PCM by the same practitioner** in the same month. A different practitioner may bill PCM for another condition.

A patient who stops can still be billed for the month they stop in, since stopping takes effect at the end of the month.

## Health centers

RHCs and FQHCs bill these individual codes. CMS ended G0511 (billing was allowed "through September 30, 2025"). They're paid separately from the RHC AIR or FQHC PPS rate, at national non-facility fee schedule rates. Part B coinsurance applies, which the consent covers.

## Who can open what

| Page | Check | Same as |
|---|---|---|
| Month list and menu entry | `patients/med` | the Staff Worklist |
| The month list's CSV | `acct/bill` | OpenEMR's billing pages |
| The patient's page (enroll, consent, log time) | `patients/med` write | the Staff Worklist's actions |
| Dashboard card | `patients/med` | the module's pages |

Every view and every change is written to OpenEMR's audit log. Time entries are never edited: a wrong one is removed, and the removal is kept with who made it and when.

## Not done yet

- **Creating the month's encounter and fee sheet lines.** It writes billing, so it waits until the month list has been used.
- **The services above** that aren't covered.
- **Checking the care plan itself.** CMS requires an electronic care plan; OpenEMR's Care Plan form is where it lives.

## Install

```
composer require anun333/oe-module-care-management
```

Then in OpenEMR, Modules › Manage Modules: Register, Install and Enable **Care Management**.

## Compatibility

Tested with synthetic data on OpenEMR 8.4.1 (Docker image `openemr/openemr:8.4.1`), with `dev/care_management_check.py` in the openhealthcare repository. That check:
- installs the module through Module Manager;
- enrolls five patients with conditions from their problem lists;
- logs staff and practitioner time across two months;
- confirms the codes by both CCM routes, BHI, and each blocker (no consent, CCM and PCM by one practitioner, no initiating visit);
- stops an enrollment;
- checks that time logged as the practitioner's by someone else becomes staff time;
- produces the month list, its CSV and the card;
- removes entries;
- removes everything.

The same check passes on current master (8.5.0-dev, 1faefbbeb9, 2026-10-18). It uses OpenEMR features present since 8.1.0, but it hasn't been run on 8.1 to 8.4.0. It needs PHP 8.3 or later.

## Development

```
composer install
vendor/bin/phpunit
php tools/footprint.php          # regenerate FOOTPRINT.md after changing the module
php tools/footprint.php --check  # what CI runs
```

The unit tests need no database or OpenEMR install. CI runs them, and the footprint check, on PHP 8.3, 8.4 and 8.5.

## Sources

Read 2026-10-18:
- CMS, [Chronic Care Management Services](https://www.cms.gov/files/document/chroniccaremanagement.pdf) (MLN909188, June 2025), and the [CCM FAQ](https://www.cms.gov/files/document/chronic-care-management-faqs.pdf).
- CMS, [Behavioral Health Integration Services](https://www.cms.gov/files/document/mln909432-behavioral-health-integration-services.pdf) (MLN909432, January 2026).
- The CY 2025 and CY 2026 Physician Fee Schedule final rules (89 FR 97710, 90 FR 49266).
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

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
