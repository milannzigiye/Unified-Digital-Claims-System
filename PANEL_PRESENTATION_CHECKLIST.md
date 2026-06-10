# Panel Presentation Drill Checklist (Project-Wide)
System: UNIFIED DIGITAL CLAIMS SYSTEM  
Purpose: Live demo self-questions that prove both happy-path strength and fail-safe behavior

## 0) Presentation Run Order (Use This First)

### A. Pre-Demo Setup
- Open the landing page: `index.php`
- Keep these four browser sessions ready:
  - Claimant session
  - Legal session
  - Finance session
  - Admin session
- Prepare one valid claimant account and one valid account for each staff role:
  - `claimant`
  - `legal`
  - `finance`
  - `admin`
- Prepare one invalid claimant submission set:
  - missing required document, or
  - wrong OCR content, or
  - wrong relationship proof
- Prepare one valid claimant submission set that passes OCR.
- Make sure at least one accepted legal officer and one accepted finance officer exist before the main happy-path demo.

### B. Fastest Strong Demo Sequence
Use this order if the panel gives you limited time.

#### 1. Show controlled entry points
- Open `index.php`
- Say: "The system separates claimant access from internal bank staff access at the very first touchpoint."
- Click:
  - `Claimant Access`
  - `Staff Access`
- Expected outcome:
  - Claimants go to `claimant-access.php`
  - Staff go to `login.php`

#### 2. Show wrong-portal denial
- In `login.php`, try claimant credentials.
- Say: "If a claimant attempts to use the staff portal, the system does not let role boundaries collapse."
- Expected outcome:
  - login blocked
  - user is guided toward claimant access

#### 3. Show claimant onboarding
- Open `claimant-access.php?mode=signup`
- Create or use a claimant account
- Complete OTP verification
- Say: "Claimants must verify identity before they can enter the workflow."
- Expected outcome:
  - OTP gate is enforced
  - claimant is routed correctly after verification

#### 4. Show OCR rejection path first
- In claimant portal, open `Claimant/form_v2.php`
- Enter a claim with invalid or mismatched required documents
- Click submit
- Say: "Bad or incomplete evidence is blocked before it contaminates legal and finance review."
- Expected outcome:
  - submission blocked
  - clear rejection reason shown
  - claimant can re-read the message later

#### 5. Show corrected claimant submission
- Fix the documents
- Submit again
- Say: "Once the required information is complete and readable, the claim can move forward."
- Expected outcome:
  - claim is submitted successfully
  - routed to legal review
  - audit trail begins

#### 6. Show claimant-side visibility
- Open `Claimant/claims.php`
- Open the submitted claim
- Say: "The claimant is not left in the dark. They can track the case and review stored feedback."
- Expected outcome:
  - status visible
  - claim snapshot opens
  - message log and supporting details are readable

#### 7. Show legal review
- Sign in as legal officer
- Open `Legal/claims.php`
- Open the assigned claim
- Say: "Legal does not receive every claim blindly. The claim arrives only after OCR gating, then legal checks disclosure and sufficiency."
- Perform:
  - review claim
  - approve / transfer to finance
- Expected outcome:
  - claim leaves legal stage
  - finance assignment is visible
  - notifications/audit trail update

#### 8. Show finance review and settlement control
- Sign in as finance officer
- Open `Finance/claims.php`
- Open the same claim
- Say: "Finance does not re-decide inheritance. Finance verifies BK-held assets, operational feasibility, and records the bank-side settlement outcome."
- Perform:
  - enter verified asset value if needed
  - approve for disbursement / complete finance action
- Expected outcome:
  - finance status updates correctly
  - assessed value is stored
  - claim progresses to final stages

#### 9. Show admin oversight
- Sign in as admin
- Open:
  - `Admin/claims.php`
  - `Admin/activity.php`
- Say: "Admin does not process claims directly. Admin governs access, oversight, and auditability."
- Expected outcome:
  - admin sees the same claim lifecycle
  - admin can open the claim snapshot from claims and from activity
  - system activity shows the exact path across roles

#### 10. Show export evidence
- Export PDF from:
  - claimant claims
  - legal claims review
  - finance claims review
  - admin claims review
  - admin system activity trail
- Say: "The same workflow is reportable, not just visible on-screen."
- Expected outcome:
  - PDFs open
  - filters are respected
  - report branding and structure are consistent

---

## 0.1) Exact Click Path by Role

### Claimant path
1. `index.php`
2. Click `Claimant Access`
3. `claimant-access.php?mode=signup` or `claimant-access.php?mode=login`
4. `verify-otp.php` if not yet verified
5. `Claimant/form_v2.php` for a new claim
6. `Claimant/claims.php` to track submitted claims
7. `Claimant/view_claim.php?id=...` when opening a specific claim

### Staff path
1. `index.php`
2. Click `Staff Access`
3. `login.php`
4. Then one of:
   - `Admin/dashboard.php`
   - `Legal/dashboard.php`
   - `Finance/dashboard.php`

### Admin oversight path
1. `Admin/dashboard.php`
2. `Admin/claims.php`
3. `Admin/activity.php`
4. `Admin/accounts.php`
5. `Admin/messaging.php`

### Legal processing path
1. `Legal/dashboard.php`
2. `Legal/claims.php`
3. open claim
4. approve, reject, or request more information

### Finance processing path
1. `Finance/dashboard.php`
2. `Finance/claims.php`
3. open claim
4. verify BK-side asset facts
5. approve / return / complete finance decision

---

## 0.2) What To Deliberately Trigger During Demo

### Must-show denial cases
- Claimant tries staff portal
- Staff tries claimant portal
- Invalid OTP
- Missing required OCR document
- Wrong relationship proof
- Attempt to edit a claim that is no longer editable

### Must-show success cases
- Correct OTP verification
- Valid claimant submission
- Legal transfer to finance
- Finance completion
- Admin activity visibility
- PDF export

---

## 0.3) What To Say At Each Stage

### Entry stage
- "The system separates public claimant access from internal staff access before authentication even completes."

### OCR stage
- "OCR is used as an intake gate, not as a final legal judge."

### Legal stage
- "Legal validates relationship disclosure, contradictions, and reviewability before finance touches the case."

### Finance stage
- "Finance confirms BK-held assets and payout feasibility, then records the bank-side outcome."

### Admin stage
- "Admin provides governance, role control, and full traceability across the workflow."

---

## 0.4) Last-Minute Smoke Test Before Presentation

Run these five checks right before you start:
- Open `index.php` and confirm both access buttons work.
- Log in one claimant and open `Claimant/claims.php`.
- Log in legal and confirm the queue loads.
- Log in finance and confirm the queue loads.
- Log in admin and export one PDF from claims or activity.

If all five pass, the live demo path is safe enough to start.

---

How to use live:
- Read each question out loud.
- Perform the action.
- State whether the outcome was expected success or expected denial.
- Mark the status column.

Status key:
- `PASS` = expected successful behavior observed
- `EXPECTED DENY` = expected block/rejection observed
- `FAIL` = system behavior did not match expected result

## A) Landing, Entry, and Role Segmentation
| ID | Self-Question to Ask While Presenting | Live Action | Expected Result | What This Proves | Status |
|---|---|---|---|---|---|
| ENT-01 | Have I shown that the landing page navigation stays usable while scrolling? | Scroll landing top to bottom | Top nav remains accessible and usable | Navigation continuity and usability | |
| ENT-02 | Have I shown that landing navigation links jump to the right sections? | Click each landing nav item | Correct section anchor opens | Information architecture is intentional | |
| ENT-03 | Have I shown that claimant entry is separate from staff entry? | Click `Claimant Access` and `Staff Access` | Two distinct access flows | Role separation starts at entry point | |
| ENT-04 | Have I shown that staff access is visible but not the primary CTA? | Point out nav hierarchy | Staff option is present but secondary | UX prioritizes claimant journey | |
| ENT-05 | Have I shown branding consistency (`BK logo + UDCS`) across key entry pages? | Open landing, staff login, claimant access | Branding remains consistent | Cohesive system identity | |
| ENT-06 | Have I shown that only the landing page carries the watermark placement? | Compare landing vs portal pages | Watermark appears only on landing | Controlled visual behavior | |

## B) Authentication, OTP, and Portal Boundaries
| ID | Self-Question to Ask While Presenting | Live Action | Expected Result | What This Proves | Status |
|---|---|---|---|---|---|
| AUTH-01 | Have I tried signing in as a claimant on the staff access page? | Use claimant credentials in `login.php` | Blocked and guided to claimant access | Wrong-portal misuse is handled safely | |
| AUTH-02 | Have I tried signing in as staff on claimant access? | Use staff credentials in `claimant-access.php` | Blocked and guided to staff access | Role boundaries are enforced both ways | |
| AUTH-03 | Have I shown unaccepted staff cannot enter the system? | Try login with legal/finance `acceptance=No` | Access denied with clear warning | Admin approval gate is enforced | |
| AUTH-04 | Have I shown duplicate admin sign-up is blocked? | Attempt second admin registration | Admin creation denied | Privileged role is controlled | |
| AUTH-05 | Have I shown invalid staff role values are rejected? | Post signup with invalid role | Signup blocked | Input-level role hardening | |
| AUTH-06 | Have I shown wrong password handling is clear and safe? | Enter wrong password | Login denied, clear message | Basic auth failure path works | |
| AUTH-07 | Have I shown unknown email handling? | Use unregistered email | Account not found / denied | No ghost-user access | |
| AUTH-08 | Have I shown OTP is required for unverified users? | Login unverified account | Redirect to OTP verification | Email verification guardrail works | |
| AUTH-09 | Have I shown malformed OTP input gets rejected? | Enter less/more than 6 digits | OTP rejected with message | OTP format validation | |
| AUTH-10 | Have I shown invalid OTP gets rejected? | Enter wrong 6-digit OTP | Verification denied | OTP correctness enforcement | |
| AUTH-11 | Have I shown successful OTP returns user to correct portal flow? | Enter correct OTP | Redirects to correct return page | Flow continuity after verification | |
| AUTH-12 | Have I shown first-time claimant routing works? | New claimant signs in after OTP | Redirects to claim form | First-session logic is explicit | |
| AUTH-13 | Have I shown returning claimant routing works? | Existing claimant signs in | Redirects to claimant dashboard | Stateful UX logic works | |

## C) Claimant Claim Submission Happy Path
| ID | Self-Question to Ask While Presenting | Live Action | Expected Result | What This Proves | Status |
|---|---|---|---|---|---|
| CLM-01 | Have I shown the multi-step claim flow can be completed end-to-end? | Complete steps 1-4 with valid data | Claim submits successfully | Core claimant journey is functional | |
| CLM-02 | Have I shown only defendable asset types are presented? | Open `Claiming Property/Assets` dropdown | Bank-relevant options only | Domain scope is realistic and defendable | |
| CLM-03 | Have I shown settlement method options adapt to asset type? | Switch claim type | Method list updates accordingly | Context-aware disbursement UX | |
| CLM-04 | Have I shown required settlement details are captured structurally? | Pick method requiring details | Structured fields appear and save | No free-text-only disbursement weakness | |
| CLM-05 | Have I shown successful submit creates legal assignment? | Submit valid claim | Claim routed to legal queue | Workflow automation works | |
| CLM-06 | Have I shown submit creates auditable traces? | Check admin activity | `claim_submitted` event exists | Traceability for panel evidence | |
| CLM-07 | Have I shown submit feedback is clear to claimant? | Submit and observe feedback | Toast + message log entries shown | User guidance and transparency | |

## D) Claimant Validation and OCR Fail-Safe Paths
| ID | Self-Question to Ask While Presenting | Live Action | Expected Result | What This Proves | Status |
|---|---|---|---|---|---|
| OCR-01 | Have I shown required document omission is blocked? | Submit without one required doc | Submission blocked with clear reason | Required-doc gate works | |
| OCR-02 | Have I shown unsupported required file type is blocked? | Upload PDF for required OCR doc | Rejected (JPG/PNG required) | OCR compatibility rules enforced | |
| OCR-03 | Have I shown oversized required file is blocked? | Upload >10MB required doc | Rejected with size message | Upload limits enforced | |
| OCR-04 | Have I shown wrong death certificate content is rejected? | Upload non-death certificate image | Verification fails with reason | Document-type detection works | |
| OCR-05 | Have I shown death cert name mismatch is rejected? | Use mismatching deceased name | Verification fails with reason | Data/document cross-check works | |
| OCR-06 | Have I shown death date mismatch is rejected? | Enter wrong date vs doc | Verification fails with reason | Critical field consistency check | |
| OCR-07 | Have I shown wrong relationship proof type is rejected? | Select spouse, upload child-type proof | Verification fails with reason | Relationship-type logic is enforced | |
| OCR-08 | Have I shown relationship names mismatch is rejected? | Upload proof with unrelated names | Verification fails with reason | Claimant/deceased linkage is verified | |
| OCR-09 | Have I shown invalid ID marker document is rejected? | Upload random image as ID | Verification fails with reason | ID authenticity marker checks | |
| OCR-10 | Have I shown invalid Rwanda ID/passport pattern is rejected? | Upload ID with no valid number pattern | Verification fails with reason | Pattern-level ID validation works | |
| OCR-11 | Have I shown unreadable/blurred OCR docs fail gracefully? | Upload blurred images | Clear rejection reason appears | Fail-safe messaging quality | |
| OCR-12 | Have I shown claim rollback after required OCR failure? | Trigger OCR failure and check claims list | Failed submission does not persist claim | No dirty/incomplete claims enter workflow | |
| OCR-13 | Have I shown claimant can re-read rejections later? | Close popup, open message log | Same messages are preserved | No loss of critical guidance | |

## E) Settlement and Disbursement Logic (Positive + Negative)
| ID | Self-Question to Ask While Presenting | Live Action | Expected Result | What This Proves | Status |
|---|---|---|---|---|---|
| DST-01 | Have I shown claim type and method mismatch is blocked? | Select disallowed method for asset type | Validation error shown | Method-to-asset compatibility guardrail | |
| DST-02 | Have I shown required disbursement fields cannot be skipped? | Leave required fields blank | Save/submit blocked | Completeness enforcement | |
| DST-03 | Have I shown phone format checks? | Enter bad phone values | Validation error shown | Contact data quality control | |
| DST-04 | Have I shown account number format checks? | Enter too short/invalid account | Validation error shown | Banking destination quality control | |
| DST-05 | Have I shown nominee ID validation for Rwanda context? | Enter invalid nominee ID/passport | Validation error shown | Rwanda-specific integrity checks | |
| DST-06 | Have I shown split payout must total 100%? | Enter non-100 split percentages | Save/submit blocked | Financial consistency control | |
| DST-07 | Have I shown installment constraints (count/frequency/date)? | Enter invalid installment values | Validation error shown | Disbursement schedule integrity | |

## F) Claimant Post-Submission Control Paths
| ID | Self-Question to Ask While Presenting | Live Action | Expected Result | What This Proves | Status |
|---|---|---|---|---|---|
| PST-01 | Have I shown only pending claims can be edited? | Try edit pending vs non-pending | Pending editable, others blocked | Status-aware claimant controls | |
| PST-02 | Have I shown only pending claims can be deleted? | Try delete pending vs non-pending | Pending deletable, others blocked | Protection against late-stage tampering | |
| PST-03 | Have I shown claim edit updates are persisted? | Update pending claim | Success response and refreshed values | Edit flow is complete | |
| PST-04 | Have I shown replacement document validation (5MB/type)? | Replace with invalid file then valid file | Invalid rejected, valid accepted | Safe document maintenance | |
| PST-05 | Have I shown ownership checks on claim detail APIs? | Access another claimant claim ID | Access denied | Data isolation between claimants | |
| PST-06 | Have I shown delete path is fail-safe? | Trigger delete and refresh | Claim removed; no partial UI corruption | Controlled destructive operation | |

## G) Legal Queue and Decision Guardrails
| ID | Self-Question to Ask While Presenting | Live Action | Expected Result | What This Proves | Status |
|---|---|---|---|---|---|
| LEG-01 | Have I shown legal sees only assigned claims? | Login legal user and inspect list | Only assigned records shown | Queue scoping is enforced | |
| LEG-02 | Have I shown legal cannot act on unassigned claim IDs? | Submit action on foreign claim | Action denied | Anti-cross-queue tampering | |
| LEG-03 | Have I shown legal transfer is allowed only in pending/under-review? | Transfer from allowed and disallowed states | Allowed works, disallowed blocked | State machine integrity | |
| LEG-04 | Have I shown legal transfer fails if no active finance officer exists? | Remove/disable finance assignees and transfer | Transfer denied with clear reason | Safe dependency handling | |
| LEG-05 | Have I shown legal rejection path works with reason trail? | Reject with comment | Status updated + reason appended | Transparent decision record | |
| LEG-06 | Have I shown empty legal comment is blocked? | Try comment action with blank text | Validation block | Review quality control | |
| LEG-07 | Have I shown claimant and finance are notified on transfer? | Transfer claim and check notifications | Both parties receive notifications | Multi-party workflow communication | |

## H) Finance Queue and Decision Guardrails
| ID | Self-Question to Ask While Presenting | Live Action | Expected Result | What This Proves | Status |
|---|---|---|---|---|---|
| FIN-01 | Have I shown finance sees only assigned claims? | Login finance user and inspect list | Only assigned records shown | Queue scoping is enforced | |
| FIN-02 | Have I shown finance cannot approve/reject outside transferred state? | Try actions on non-transferred status | Action blocked | Final-stage state integrity | |
| FIN-03 | Have I shown finance approval updates status correctly? | Approve transferred claim | `approved by finance` set | Final settlement decision flow works | |
| FIN-04 | Have I shown finance rejection updates status correctly? | Reject transferred claim | `rejected by finance` set | Explicit denial path works | |
| FIN-05 | Have I shown empty finance comment is blocked? | Submit comment action with blank value | Validation block | Reviewer accountability | |
| FIN-06 | Have I shown claimant notification occurs on finance decisions? | Approve/reject and inspect claimant notifications | Decision notifications present | Claimant receives lifecycle visibility | |

## I) Admin Oversight, Control, and Traceability
| ID | Self-Question to Ask While Presenting | Live Action | Expected Result | What This Proves | Status |
|---|---|---|---|---|---|
| ADM-01 | Have I shown admin can filter claims by status/date/search? | Use claim filters and shortcuts | Table updates correctly | Operability at scale | |
| ADM-02 | Have I shown admin can open full claim detail modal from claims page? | Click view icon | Modal opens with rich claim info | Case-level oversight | |
| ADM-03 | Have I shown admin claim modal includes settlement method/details? | Inspect modal fields | Distribution data visible | Disbursement transparency | |
| ADM-04 | Have I shown admin can message claimant directly from claim row icon? | Click message icon on claim row | DM opens with target contact | Fast intervention capability | |
| ADM-05 | Have I shown activity log trails all roles and system actions? | Open activity list and filter by role | Events visible for claimant/legal/finance/system | End-to-end accountability | |
| ADM-06 | Have I shown admin activity can open claim snapshot from each event? | Click `View claim` in activity | Claim context modal opens | Event-to-claim drilldown works | |
| ADM-07 | Have I shown account acceptance updates trigger staff notification? | Toggle staff approval status | Status updates and notification created | Access governance + communication | |
| ADM-08 | Have I shown staff account removal is controlled? | Delete legal/finance account | User removed; no orphan UI crash | Administrative cleanup path | |

## J) Assignment Logic and Capacity Scenarios
| ID | Self-Question to Ask While Presenting | Live Action | Expected Result | What This Proves | Status |
|---|---|---|---|---|---|
| ASG-01 | Have I shown claims are balanced when multiple legal officers exist? | Submit several new claims with 2+ legal officers active | Claims spread across legal assignees | Load-aware routing is active | |
| ASG-02 | Have I shown all claims route to one officer when only one legal exists? | Keep one legal accepted and submit claims | Same assignee receives all | Correct single-resource behavior | |
| ASG-03 | Have I shown admin escalation when no legal officer exists? | Set legal none active, submit claim | Admin receives assignment-issue notification | Safe no-capacity fallback | |
| ASG-04 | Have I shown legal->finance assignment picks active finance officer? | Transfer from legal with multiple finance users | Claim assigned to finance queue | Stage-to-stage balancing works | |
| ASG-05 | Have I shown legacy/unassigned claims can be backfilled? | Open admin/legal/finance queues after creating unassigned test | Missing assignees get filled | Recovery for historical data drift | |

## K) Messaging and Notification Reliability
| ID | Self-Question to Ask While Presenting | Live Action | Expected Result | What This Proves | Status |
|---|---|---|---|---|---|
| MSG-01 | Have I shown DM send works for each role? | Send message as claimant/legal/finance/admin | Message stored and rendered | Role-wide communication works | |
| MSG-02 | Have I shown empty DM content is blocked? | Attempt send with blank body | Send denied with clear message | Input hygiene in communications | |
| MSG-03 | Have I shown sender spoofing is blocked? | Tamper sender_id in request | Request denied | Sender identity verification | |
| MSG-04 | Have I shown notification text grammar is complete? | Trigger message notifications | Messages read clearly (no broken sentence tails) | Communication polish and trust | |
| MSG-05 | Have I shown `Mark read` works on single notifications? | Mark one unread item | Item updates to read | Individual notification control | |
| MSG-06 | Have I shown `Mark all read` works? | Click mark-all in notification panel | All unread become read | Bulk notification control | |
| MSG-07 | Have I shown notifications auto-refresh? | Wait or trigger events in another tab | New notifications appear on polling | Near-real-time status visibility | |
| MSG-08 | Have I shown message content is safely rendered? | Send message containing HTML tags | Tags display as text, not executed | XSS-resilient message rendering | |

## L) Reports and Evidence Exports
| ID | Self-Question to Ask While Presenting | Live Action | Expected Result | What This Proves | Status |
|---|---|---|---|---|---|
| RPT-01 | Have I shown admin summary report generation? | Export admin summary | PDF opens successfully | Management reporting availability | |
| RPT-02 | Have I shown detailed reports include settlement fields? | Export detailed report and inspect columns | Method/details visible in output | Disbursement traceability in reports | |
| RPT-03 | Have I shown settlement method breakdown section appears? | Export summary and inspect breakdown | Method distribution table present | Analytical visibility for panel defense | |
| RPT-04 | Have I shown legal report is scoped to logged-in legal assignee? | Export as legal user | Only legal-scope data included | Least-privilege reporting | |
| RPT-05 | Have I shown finance report is scoped to logged-in finance assignee? | Export as finance user | Only finance-scope data included | Least-privilege reporting | |
| RPT-06 | Have I shown report filters (status/type/date/search) work? | Apply filters and export | Output reflects selected filters | Controlled report slicing | |

## M) Security and Abuse-Case Demonstrations
| ID | Self-Question to Ask While Presenting | Live Action | Expected Result | What This Proves | Status |
|---|---|---|---|---|---|
| SEC-01 | Have I shown direct URL access to wrong role portals is denied? | Open role page without matching role session | Redirect/deny occurs | URL tampering resistance | |
| SEC-02 | Have I shown claimant cannot read another claimant's claim detail endpoint? | Call detail endpoint with foreign claim ID | Access denied | Data ownership isolation | |
| SEC-03 | Have I shown claimant cannot replace documents on foreign claims? | Attempt replace with foreign claim/doc IDs | Access denied | Document-level ownership enforcement | |
| SEC-04 | Have I shown legal/finance cannot transition unauthorized statuses? | Force invalid transitions | Server denies transition | Back-end state validation, not UI-only | |
| SEC-05 | Have I shown notification read updates are ownership-bound? | Try marking another user's notification ID | Update denied or no effect | Notification privacy guardrail | |
| SEC-06 | Have I shown invalid claim IDs are rejected safely? | Send zero/negative/nonexistent IDs | Error response, no crash | Input hardening on IDs | |
| SEC-07 | Have I shown session expiration behavior is safe? | Expire session then perform protected action | Redirect or unauthorized response | Safe failure after auth loss | |

## N) UX, Legibility, and Accessibility Smoke Checks
| ID | Self-Question to Ask While Presenting | Live Action | Expected Result | What This Proves | Status |
|---|---|---|---|---|---|
| UX-01 | Have I shown form labels and fields are clearly visible? | Open major forms in claimant/legal/finance/admin | Inputs and labels are legible | Readability target is met | |
| UX-02 | Have I shown keyboard focus visibility? | Tab through inputs/buttons | Visible focus ring appears | Keyboard accessibility | |
| UX-03 | Have I shown validation messages are visible and specific? | Trigger field errors intentionally | Clear inline errors near fields | Usable error guidance | |
| UX-04 | Have I shown responsive behavior under browser zoom? | Test 90%, 110%, 125% zoom | Layout remains usable without broken nav | Practical responsiveness | |
| UX-05 | Have I shown navbar/sidebar remain usable on long pages? | Scroll long pages in each role | Navigation remains accessible | Workflow continuity | |
| UX-06 | Have I shown popup messages do not disappear before comprehension? | Trigger success/error messages | Readable timing + persistent message log | Human-readable feedback design | |
| UX-07 | Have I shown portal pages keep consistent visual language? | Compare dashboard/claims/profile/messaging pages by role | Shared component style evident | System-wide UI consistency | |

## O) Final "Show-Off" Sequence (Fast Panel Run)
| Step | Demo Move | Expected Outcome |
|---|---|---|
| 1 | Try claimant login on staff portal | Expected denial with guidance to claimant access |
| 2 | Sign in claimant correctly | Successful claimant access and routing |
| 3 | Submit claim with wrong document first | Expected rejection with clear reason |
| 4 | Re-submit with corrected documents | Claim accepted and routed to legal |
| 5 | In legal, transfer to finance | Valid state transition and notifications |
| 6 | In finance, approve claim | Final decision applied with audit trail |
| 7 | In admin, open activity and view that exact claim path | Full lifecycle trace visible end-to-end |

## P) Panel Q&A Backup Prompts (If They Challenge You)
| Likely Panel Challenge | Fast Answer Direction |
|---|---|
| "What if user enters through wrong portal?" | Show AUTH-01/AUTH-02 |
| "What if documents are fake or mismatched?" | Show OCR-04 to OCR-11 |
| "How do you prevent invalid disbursement details?" | Show DST-01 to DST-07 |
| "How do you avoid overloading one reviewer?" | Show ASG-01/ASG-02/ASG-03 |
| "How can admin prove what happened?" | Show ADM-05 and reports RPT-01 to RPT-06 |
| "Do users lose important feedback popups?" | Show OCR-13 and UX-06 |
