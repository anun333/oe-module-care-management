# Care Management: staff guide

For the staff who use the module day to day, task by task. Which codes it counts and why, and how to install it, are in the [README](README.md).

The module bills nothing. It records enrollment, consent and time, shows each month which codes the time meets, and checks what was billed against it. Staff add the codes on a fee sheet, as for any other service. It has been tested only on made-up patients; check the codes and rules with your payers.

## Care managers and other clinical staff

### Enroll a patient

*Where:* the patient's dashboard, the Care Management card › **Enroll, consent and log time**, then **Enroll** at the bottom of the page.

1. Choose the **Service** and the **Billing practitioner**, and the **Start** date.
2. Tick the conditions managed. They come from the patient's active problem list, so add a missing condition there first. CCM needs two or more chronic conditions, PCM one high-risk condition, BHI and CoCM the behavioral health condition.
3. For APCM, tick **For APCM: the patient is a Qualified Medicare Beneficiary (QMB)** if they are.
4. If consent was given at the same time, fill in the consent fields (next task). Otherwise leave them for later.
5. Add a **Note** if useful, and choose **Enroll**.

### Record consent

*Where:* the patient's Care Management page, under the service › **Record consent**.

1. Enter the **Consent date**, and whether the **Consent was** **Written** or **Verbal**.
2. If the billing practitioner hasn't seen the patient in the year before, enter the **Initiating visit, if needed**. Only a visit with the billing practitioner counts, for every service. For APCM the window is three years.
3. Tick each thing the patient was told. CMS requires the first four, and for BHI and CoCM the fifth as well.
4. Choose **Save consent**.

A month can't be billed until consent is recorded with everything the patient must be told; the card and the month list say so.

### Log time

*Where:* the patient's Care Management page, under the service.

1. Set the **Date**, the **Minutes** (1 to 240) and **What was done**.
2. If you are the billing practitioner and did the work yourself, tick **I did this myself**: the practitioner's own time meets different codes from clinical staff time.
3. Choose **Log time**.

*What you see:* this month's minutes for each service against what its codes need, and how many more minutes the next code needs. **Time logged** lists the month's entries; **Remove** takes one out, and the removal is kept.

Time is counted per calendar month and never carries over. Use **Month** and **Show** to look at another month.

### Record that the patient stopped

*Where:* the patient's Care Management page, under the service › **The patient stopped**.

Enter **Stopped on** and choose **Stop**. It takes effect at the end of that month, which is the last month that can be billed.

## The billing practitioner

### Record complex decision-making (complex CCM)

Complex CCM (99487) needs the billing practitioner to record, each month, that they made moderate or high complexity medical decisions for the patient.

*Where:* the patient's Care Management page, under the CCM service.

Tick the decision-making box and choose **Save**. Only the billing practitioner can record it, and it applies to the month shown.

## Billing staff

### Check the month before billing

*Where:* the main menu › **Care Management**.

1. Choose the **Month** and **Show**.
2. Read each enrollment's **Codes met** and **Blocked by**. A month blocked by consent, conditions or an initiating visit can't be billed until that's fixed.
3. Add the codes met to a fee sheet, as for any other service.
4. Choose **Download CSV** for a copy to work from.

*What you see:* if you can see billing, the **Billed** column compares the codes on the patient's fee sheets for that month's encounters with the codes the time met: **Billed as met**, **Billed beyond the time logged**, **Billed, though something blocks billing**, or **Met, not billed**. While the month is running, a met month shows **Met, not billed yet**.

*What it doesn't do:* it never adds a code to a fee sheet, and it doesn't submit claims.

### Follow up from the Staff Worklist

If the Staff Worklist module is installed, it has a **Care management, not billed** tab under **Billing practitioner**: each enrollment whose month, in the last three completed months, met a code that no fee sheet has billed. Its button opens the month list.

CPT is a registered trademark of the American Medical Association. The module shows CPT numbers only, never AMA's descriptions.
