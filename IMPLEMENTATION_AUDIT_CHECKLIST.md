# Implementation Audit Checklist and Capabilities Map
Generated: 2026-03-01  
System: UNIFIED DIGITAL CLAIMS SYSTEM  
Scope focus: claimant data handling, deceased-asset claim processing, guardrails, and recovery paths

Legend:
- `[x]` Implemented guardrail/capability
- `[~]` Implemented with notable residual risk
- `[ ]` Not implemented

## 1) Identity, Session, and Access Control
| ID | Checklist Item | Guardrail Implemented | Recovery/Error Path | Evidence |
|---|---|---|---|---|
| IAM-01 | `[x]` Role-gated web pages | `require_web_role()` enforces session role and DB re-validation before page use | Redirects to login if invalid | `auth.php:23`, `auth.php:34`, `auth.php:30` |
| IAM-02 | `[x]` Role-gated API endpoints | `require_api_role()` verifies role and DB user existence | Returns `Unauthorized` and exits | `auth.php:48`, `auth.php:54`, `auth.php:60` |
| IAM-03 | `[x]` Secure session cookie posture | Strict-mode cookies, `httponly`, `samesite=Lax`, secure-on-HTTPS | Session starts with hardened parameters | `security.php:14`, `security.php:15`, `security.php:17`, `security.php:20` |
| IAM-04 | `[x]` Staff and claimant login separation | Staff login blocks claimant route; claimant portal blocks staff role | Clear warning and redirect path | `login.php:127`, `claimant-access.php:107` |
| IAM-05 | `[x]` Staff acceptance gate | Staff must be `acceptance='Yes'` for access | Warning shown, no session login | `login.php:139` |
| IAM-06 | `[x]` OTP verification gate before portal access | Unverified users forced through OTP flow | OTP is regenerated and emailed; redirected to verification page | `login.php:145`, `login.php:149`, `claimant-access.php:119`, `claimant-access.php:123` |
| IAM-07 | `[x]` OTP return URL allowlist | Only known return targets are permitted | Unsafe return value is replaced with staff login | `verify-otp.php:11`, `verify-otp.php:13`, `verify-otp.php:14` |
| IAM-08 | `[x]` First-time claimant routing | Claimant is routed based on existing claims (submit first vs dashboard) | Correct first-use UX path chosen automatically | `claimant-access.php:150`, `claimant-access.php:161` |

## 2) Claimant Intake Data Capture and Input Guardrails
| ID | Checklist Item | Guardrail Implemented | Recovery/Error Path | Evidence |
|---|---|---|---|---|
| CLM-01 | `[x]` Claimant-only claim submission access | Claim form requires claimant session and claimant DB role | Redirects to claimant access if invalid | `Claimant/form.php:11`, `Claimant/form.php:19`, `Claimant/form.php:21` |
| CLM-02 | `[x]` Core deceased fields are mandatory in UX | Name, deceased ID/passport, date, claim type required in UI | Section-level inline validation blocks submit | `Claimant/form.php:2171`, `Claimant/form.php:2177`, `Claimant/form.php:2185`, `Claimant/form.php:2199`, `Claimant/form.php:3681` |
| CLM-03 | `[x]` Claim type options constrained to defendable asset classes | Dropdown now limited to bank/savings/fixed deposit/shares/investment | Invalid/empty selection blocked | `Claimant/form.php:2200`, `Claimant/form.php:2202`, `Claimant/form.php:2207` |
| CLM-04 | `[x]` Relationship required | Relationship selector required and validated | Inline error shown | `Claimant/form.php:2344`, `Claimant/form.php:3778` |
| CLM-05 | `[x]` Optional alt contact validation | Alternative email validated when supplied | Inline error and block on invalid format | `Claimant/form.php:2332`, `Claimant/form.php:3784`, `Claimant/form.php:3787` |
| CLM-06 | `[x]` Duplicate-claim prevention | Same claimant + deceased name + deceased ID + claim type is blocked | Error toast and early exit | `Claimant/form.php:133`, `Claimant/form.php:136`, `Claimant/form.php:149` |
| CLM-07 | `[x]` Default safe initial status | New claims start as `pending` | Enforces predictable workflow start | `Claimant/form.php:127`, `Claimant/form.php:128` |

## 3) Settlement / Disbursement Logic (Claim-Type Aware)
| ID | Checklist Item | Guardrail Implemented | Recovery/Error Path | Evidence |
|---|---|---|---|---|
| DST-01 | `[x]` Claim type -> allowed method matrix | Distribution methods are strictly mapped by asset type | Mismatch rejected | `components/distribution.php:41`, `components/distribution.php:46`, `components/distribution.php:50` |
| DST-02 | `[x]` Required settlement fields by method | Each method has required fields | Missing required fields rejected | `components/distribution.php:67`, `components/distribution.php:72`, `components/distribution.php:84` |
| DST-03 | `[x]` Payload parser normalization | Details JSON is parsed and sanitized key/value-wise | Invalid JSON returns explicit error | `components/distribution.php:182`, `components/distribution.php:191`, `components/distribution.php:193` |
| DST-04 | `[x]` Server-side method compatibility check | Selected method must belong to selected claim type | Rejects with actionable message | `components/distribution.php:414`, `components/distribution.php:430`, `components/distribution.php:431` |
| DST-05 | `[x]` Field-level server validation | Phone/account/name/SWIFT/ID/date/percentage constraints enforced | Any invalid field blocks save/submit | `components/distribution.php:249`, `components/distribution.php:261`, `components/distribution.php:328`, `components/distribution.php:375` |
| DST-06 | `[x]` Split payout percentage integrity | Primary + secondary split must equal 100% | Hard reject on mismatch | `components/distribution.php:457`, `components/distribution.php:460`, `components/distribution.php:461` |
| DST-07 | `[x]` Claim submit validates distribution on server | Form submission revalidates parsed payload and method mapping | Error toast + redirect back to form | `Claimant/form.php:98`, `Claimant/form.php:105`, `Claimant/form.php:112` |
| DST-08 | `[x]` Claim update revalidates distribution on server | Update endpoint repeats same validation stack | JSON error response; no partial save | `Claimant/update_claim.php:60`, `Claimant/update_claim.php:65`, `Claimant/update_claim.php:72` |
| DST-09 | `[x]` Client-side disbursement field UX validation | Edit/submit screens validate structured fields pre-submit | Immediate user feedback + no submit | `Claimant/claims.php:1741`, `Claimant/claims.php:1751`, `Claimant/claims.php:1767`, `Claimant/form.php:3725`, `Claimant/form.php:3734` |

## 4) OCR Verification for Deceased-Asset Documents
| ID | Checklist Item | Guardrail Implemented | Recovery/Error Path | Evidence |
|---|---|---|---|---|
| OCR-01 | `[x]` OCR engine with language fallback | Tesseract runs `eng+fra`, then `eng` fallback | Improves extraction resilience | `Claimant/form.php:269`, `Claimant/form.php:273`, `Claimant/form.php:275` |
| OCR-02 | `[x]` Required document set enforced | Death certificate + relationship proof + ID required | Missing one blocks submission | `Claimant/form.php:703`, `Claimant/form.php:705`, `Claimant/form.php:710`, `Claimant/form.php:727` |
| OCR-03 | `[x]` File constraints for required OCR docs | Required docs limited to JPG/PNG and 10MB | Rejects file with explicit reason | `Claimant/form.php:617`, `Claimant/form.php:621`, `Claimant/form.php:625` |
| OCR-04 | `[x]` Death certificate Rwanda-aware checks | Document markers + Rwanda civil-status markers + deceased name + date-of-death cross-check | Reject reason is specific (document incomplete, name mismatch, date mismatch) | `Claimant/form.php:395`, `Claimant/form.php:411`, `Claimant/form.php:438`, `Claimant/form.php:445`, `Claimant/form.php:447` |
| OCR-05 | `[x]` Relationship proof checks | Relationship-type keywords + Rwanda markers + claimant/deceased names | Reject reason identifies mismatch type | `Claimant/form.php:455`, `Claimant/form.php:462`, `Claimant/form.php:475`, `Claimant/form.php:482`, `Claimant/form.php:488` |
| OCR-06 | `[x]` ID document checks for Rwanda context | Rwanda ID/passport markers + number pattern + flexible claimant-name matching | Rejects on marker/number/name mismatch | `Claimant/form.php:495`, `Claimant/form.php:513`, `Claimant/form.php:517`, `Claimant/form.php:521`, `Claimant/form.php:578` |
| OCR-07 | `[x]` Temporary OCR artifact cleanup | OCR temp files removed after processing | Limits temp-data leakage | `Claimant/form.php:764`, `Claimant/form.php:765`, `Claimant/form.php:766` |
| OCR-08 | `[x]` Hard rollback on required OCR failure | Created claim row and uploaded files are deleted if required verification fails | Claimant gets detailed remediation message | `Claimant/form.php:768`, `Claimant/form.php:770`, `Claimant/form.php:773`, `Claimant/form.php:776`, `Claimant/form.php:785` |

## 5) Workflow Routing, Load Balancing, and Recovery
| ID | Checklist Item | Guardrail Implemented | Recovery/Error Path | Evidence |
|---|---|---|---|---|
| WFL-01 | `[x]` Auto-schema guard for assignment columns | Missing workflow columns/indexes are auto-created | Avoids runtime failures on legacy schema | `components/workflow.php:42`, `components/workflow.php:56`, `components/workflow.php:66` |
| WFL-02 | `[x]` Least-loaded assignee selection | Routing chooses lowest open-queue load by role | Natural balancing across multiple officers | `components/workflow.php:173`, `components/workflow.php:193`, `components/workflow.php:201` |
| WFL-03 | `[x]` Only active accepted staff receive assignments | Assignee query filters to `acceptance='Yes'` | Prevents routing to inactive users | `components/workflow.php:198`, `components/workflow.php:199` |
| WFL-04 | `[x]` Submit-time legal assignment | New claim routed to legal queue automatically | If none available: no assignee + admin notification | `Claimant/form.php:200`, `Claimant/form.php:210`, `Claimant/form.php:218` |
| WFL-05 | `[x]` Transfer-time finance assignment | Legal transfer requires active finance assignee | Transfer blocked with clear error if none available | `Legal/claims.php:94`, `Legal/claims.php:95`, `Legal/claims.php:97` |
| WFL-06 | `[x]` Backfill unassigned legacy claims | Batch pass assigns missing legal/finance assignees | Prevents orphaned queue items | `components/workflow.php:276`, `components/workflow.php:290`, `components/workflow.php:321` |
| WFL-07 | `[x]` Backfill invoked in admin/legal/finance portals | Queue repair runs during portal operations | Self-heals old data over time | `Admin/claims.php:30`, `Legal/claims.php:34`, `Finance/claims.php:36` |

## 6) Claim Mutation Controls (Edit/Delete/Document Replace)
| ID | Checklist Item | Guardrail Implemented | Recovery/Error Path | Evidence |
|---|---|---|---|---|
| MUT-01 | `[x]` Edit allowed only for owner and pending status | Endpoint checks claimant ownership + `pending` | Error JSON if invalid state | `Claimant/update_claim.php:38`, `Claimant/update_claim.php:40`, `Claimant/update_claim.php:44` |
| MUT-02 | `[x]` Delete allowed only for owner and pending status | Endpoint checks claimant ownership + `pending` | Error JSON if invalid state | `Claimant/delete_claim.php:49`, `Claimant/delete_claim.php:54`, `Claimant/delete_claim.php:57` |
| MUT-03 | `[x]` Delete is transactional | Deletes docs + claim in transaction | Rollback on failure | `Claimant/delete_claim.php:68`, `Claimant/delete_claim.php:72`, `Claimant/delete_claim.php:83`, `Claimant/delete_claim.php:136` |
| MUT-04 | `[x]` Replace document ownership chain verified | Claim ownership and doc ownership are validated | Denies access if mismatch | `Claimant/replace_document.php:46`, `Claimant/replace_document.php:50`, `Claimant/replace_document.php:61`, `Claimant/replace_document.php:65` |
| MUT-05 | `[x]` Replace document file validation | Size max 5MB; MIME restricted to JPG/PNG/PDF | Rejects with clear message | `Claimant/replace_document.php:86`, `Claimant/replace_document.php:92`, `Claimant/replace_document.php:95` |
| MUT-06 | `[x]` Claim details/doc fetch are ownership-protected | Claim/detail endpoints require claimant + claim owner | Returns explicit unauthorized/access denied payload | `Claimant/get_claim_details.php:9`, `Claimant/get_claim_details.php:49`, `Claimant/get_documents.php:6`, `Claimant/get_documents.php:16` |
| MUT-07 | `[x]` Client-side non-pending action prevention | UI disables/labels edits/deletes for non-pending claims | Prevents accidental invalid attempts | `Claimant/claims.php:417`, `Claimant/claims.php:457`, `Claimant/claims.php:466` |

## 7) Legal and Finance Stage Guardrails
| ID | Checklist Item | Guardrail Implemented | Recovery/Error Path | Evidence |
|---|---|---|---|---|
| STG-01 | `[x]` Legal actions restricted to assigned queue | Legal can only act on `assigned_legal_id = current_user` | Stops unauthorized claim actions | `Legal/claims.php:55`, `Legal/claims.php:62`, `Legal/claims.php:67` |
| STG-02 | `[x]` Legal transition controls | Transfer/reject only from `pending` or `under_review` | Invalid transitions blocked with error | `Legal/claims.php:87`, `Legal/claims.php:88`, `Legal/claims.php:165` |
| STG-03 | `[x]` Finance actions restricted to assigned queue | Finance can only act on `assigned_finance_id = current_user` | Stops unauthorized claim actions | `Finance/claims.php:58`, `Finance/claims.php:65`, `Finance/claims.php:70` |
| STG-04 | `[x]` Finance transition controls | Approve/reject only from `transferred to finance` | Invalid transitions blocked with error | `Finance/claims.php:90`, `Finance/claims.php:91`, `Finance/claims.php:147`, `Finance/claims.php:148` |
| STG-05 | `[x]` Review comments are append-only history text | Comments appended with timestamp + action metadata | Preserves chronological context | `Legal/claims.php:103`, `Legal/claims.php:227`, `Finance/claims.php:98`, `Finance/claims.php:210` |
| STG-06 | `[x]` Comment action requires non-empty input | Empty comment blocked | Inline/session error returned | `Legal/claims.php:220`, `Finance/claims.php:203` |
| STG-07 | `[x]` Claimant + internal notifications on decisions | Transfer/approve/reject emits notifications | Users get stage visibility | `Legal/claims.php:123`, `Legal/claims.php:131`, `Finance/claims.php:117`, `Finance/claims.php:174` |
| STG-08 | `[x]` Email fanout to primary + valid alternate address | Decision emails include valid alternate if provided | Extends reliability of communication | `Legal/claims.php:151`, `Legal/claims.php:152`, `Finance/claims.php:134`, `Finance/claims.php:135` |

## 8) Notifications, Messaging, and UX Readability Fail-safes
| ID | Checklist Item | Guardrail Implemented | Recovery/Error Path | Evidence |
|---|---|---|---|---|
| NTF-01 | `[x]` Notification polling and refresh cadence | Top-nav polls notifications every 30 seconds | Refreshes state if read/unread changes | `components/role_nav.php:586`, `components/role_nav.php:678`, `components/role_nav.php:679` |
| NTF-02 | `[x]` Mark-as-read ownership checks on server | Notification update constrained to current user ID/email | Prevents cross-user mark-read | `Admin/mark_notification_read.php:31`, `Admin/mark_notification_read.php:52`, `Admin/mark_notification_read.php:55` |
| NTF-03 | `[x]` Mark-all-read ownership checks on server | Batch update constrained to current user ID/email | Prevents cross-user bulk update | `Admin/mark_all_notifications_read.php:26`, `Admin/mark_all_notifications_read.php:47`, `Admin/mark_all_notifications_read.php:50` |
| NTF-04 | `[x]` Known broken message grammar corrected in retrieval | Incomplete `you sent a message to` rewritten to complete sentence | Avoids ambiguous notification text | `Admin/get_notifications.php:35`, `Admin/get_notifications.php:36`, `Legal/get_notifications.php:36`, `Finance/get_notifications.php:36`, `Claimant/get_notifications.php:36` |
| NTF-05 | `[x]` DM send endpoints verify sender identity/role | Sender must match session email+role before sending | Rejects unauthorized message attempts | `Admin/send_message.php:44`, `Admin/send_message.php:60`, `Admin/send_message.php:73` |
| NTF-06 | `[x]` DM creation mirrors notifications to both sides | Sender/receiver notification records created | Better conversation traceability | `Admin/send_message.php:91`, `Admin/send_message.php:99`, `Admin/send_message.php:103`, `Admin/send_message.php:105` |
| NTF-07 | `[x]` Message rendering escapes content | Output uses `htmlspecialchars` and preserves newlines | Mitigates XSS in message body | `Admin/get_messages.php:76`, `Legal/get_messages.php:76`, `Finance/get_messages.php:76`, `Claimant/get_messages.php:76` |
| NTF-08 | `[x]` Claimant submission popup persistence | Toast messages are copied into a saved message log | Users can re-read messages after popup closes | `Claimant/form.php:2592`, `Claimant/form.php:2601`, `Claimant/form.php:2936`, `Claimant/form.php:4217` |
| NTF-09 | `[x]` Popup timing tuned for readability | Success/info auto-hide delayed (12-16s), error/warning manual | Reduces "message disappears too fast" issue | `Claimant/form.php:4219`, `Claimant/form.php:4220`, `Claimant/form.php:4233` |

## 9) Audit Trail, Admin Oversight, and Report Defensibility
| ID | Checklist Item | Guardrail Implemented | Recovery/Error Path | Evidence |
|---|---|---|---|---|
| AUD-01 | `[x]` Activity log schema self-healing | `activity_logs` table auto-created if missing | Prevents audit feature breakage on old DBs | `components/workflow.php:75`, `components/workflow.php:87` |
| AUD-02 | `[x]` Immutable activity event writer | Central `bk_activity_log()` captures actor, role, claim, action, details, metadata | Best-effort write across flows | `components/workflow.php:108`, `components/workflow.php:146`, `components/workflow.php:154`, `components/workflow.php:155` |
| AUD-03 | `[x]` Submission/update/delete events audited | Claimant claim actions logged with metadata | Supports lifecycle traceability | `Claimant/form.php:229`, `Claimant/update_claim.php:119`, `Claimant/delete_claim.php:120` |
| AUD-04 | `[x]` Legal/finance decisions audited | Transfer/reject/approve/comment events logged | Supports accountability per role | `Legal/claims.php:139`, `Legal/claims.php:198`, `Legal/claims.php:244`, `Finance/claims.php:125`, `Finance/claims.php:181`, `Finance/claims.php:227` |
| AUD-05 | `[x]` Admin can filter and inspect all activity trails | Activity page supports role/action/search/date filters | Drill-down available through modal claim context | `Admin/activity.php:44`, `Admin/activity.php:46`, `Admin/activity.php:50`, `Admin/activity.php:54`, `Admin/activity.php:333`, `Admin/activity.php:356` |
| AUD-06 | `[x]` Admin claims page supports direct DM + detailed claim view | Claim table has message icon and modal detail payload | Faster intervention on specific claims | `Admin/claims.php:714`, `Admin/claims.php:715`, `Admin/claims.php:731`, `Admin/claims.php:732` |
| AUD-07 | `[x]` Rich export includes settlement details | Detailed reports include settlement method/details and method breakdown | Better panel-ready disbursement traceability | `Admin/export_report.php:269`, `Admin/export_report.php:384`, `Admin/export_report.php:490`, `Legal/export_report.php:264`, `Finance/export_report.php:265` |

## 10) Accessibility and UI Defensive Patterns
| ID | Checklist Item | Guardrail Implemented | Recovery/Error Path | Evidence |
|---|---|---|---|---|
| UI-01 | `[x]` Design tokens centralize readable color system | BK palette, text/muted contrast variables, focus ring variables | Consistent legibility baseline | `assets/css/tokens.css:2`, `assets/css/tokens.css:5`, `assets/css/tokens.css:6`, `assets/css/tokens.css:34` |
| UI-02 | `[x]` Tailwind maps directly to tokenized semantic colors | Color system supports opacity and role-based usage | Avoids hardcoded random colors across pages | `tailwind.config.js:43`, `tailwind.config.js:44`, `tailwind.config.js:52` |
| UI-03 | `[x]` Input/select components emit accessible attributes | Labels, required indicators, `aria-invalid`, `aria-describedby`, error `role=alert` | Users get clear field/error context | `components/input.php:41`, `components/input.php:58`, `components/input.php:75`, `components/select.php:39`, `components/select.php:52`, `components/select.php:71` |
| UI-04 | `[x]` Global focus visibility | Inputs/selects/textareas receive visible focus ring | Keyboard navigation remains visible | `assets/css/global.css:113`, `assets/css/global.css:116` |
| UI-05 | `[x]` Dismissible alerts and toasts are standardized | Alert/toast components provide consistent status display | Reduces ambiguous or transient feedback | `components/alert.php:20`, `components/alert.php:31`, `components/toast.php:19`, `components/toast.php:24` |

## 11) Process-Recovery Map (Fail-safe Paths)
| Recovery Scenario | What Happens Today | Evidence |
|---|---|---|
| Required settlement details missing/invalid | Submission/update is blocked before DB write with explicit message | `Claimant/form.php:98`, `Claimant/form.php:112`, `Claimant/update_claim.php:60`, `Claimant/update_claim.php:72` |
| Duplicate claim attempt | Request exits early with duplicate warning toast | `Claimant/form.php:133`, `Claimant/form.php:149` |
| No legal officer active at submit-time | Claim remains unassigned to legal; admins are notified | `Claimant/form.php:200`, `Claimant/form.php:210`, `Claimant/form.php:218` |
| No finance officer active at legal transfer | Transfer is denied with clear legal-side error | `Legal/claims.php:95`, `Legal/claims.php:97` |
| OCR cannot verify required documents | Created claim and files are rolled back; claimant sees actionable reasons | `Claimant/form.php:768`, `Claimant/form.php:770`, `Claimant/form.php:776` |
| Pending claim update/delete invalid state | Endpoint rejects action with user-readable JSON message | `Claimant/update_claim.php:44`, `Claimant/delete_claim.php:60` |
| Notification mark-read fails | UI reloads notification list and restores button state | `components/role_nav.php:610`, `components/role_nav.php:615` |
| Message popup dismissed too quickly | Notification content retained in message log for later review | `Claimant/form.php:2601`, `Claimant/form.php:2936`, `Claimant/form.php:4217` |
| Legacy claims missing assignees | Background backfill attempts to repopulate legal/finance assignees | `components/workflow.php:276`, `components/workflow.php:296`, `components/workflow.php:327` |

## 12) Residual Risk Register (Important for Panel Transparency)
| ID | Risk | Why It Matters | Evidence | Recommended Fix |
|---|---|---|---|---|
| RSK-01 | `[~]` OTP resend writes different DB columns than verification flow | Resent OTP may not match fields checked during verify/login | `resend_otp.php:34`, `resend_otp.php:35`, `verify-otp.php:29`, `login.php:151`, `claimant-access.php:125` | Standardize on `email_otp` + `otp_expires_at` in all OTP paths |
| RSK-02 | `[~]` OTP expiry is not enforced during verification query | Potential acceptance of old codes if still present in DB | `verify-otp.php:26`, `verify-otp.php:29` | Add `AND otp_expires_at >= NOW()` check and explicit expiry message |
| RSK-03 | `[~]` SMTP credentials hardcoded in code | Secret leakage and operational risk | `sendOtpEmail.php:12`, `sendOtpEmail.php:13` | Move mail config to env via `app_config.php` `configure_mailer()` |
| RSK-04 | `[~]` Mixed session identity keys (`email` vs `user_id`) across endpoints | Can cause edge-case auth inconsistency | `Claimant/get_documents.php:6`, `Claimant/get_documents.php:13`, `Claimant/get_claim_details.php:9`, `Claimant/get_claim_details.php:14` | Normalize to a shared auth helper and single identity source |
| RSK-05 | `[~]` Claim delete file path may miss uploaded files | Potential orphaned files after successful delete | `Claimant/form.php:258`, `Claimant/delete_claim.php:91`, `Claimant/replace_document.php:103` | Align delete cleanup path with upload path convention |
| RSK-06 | `[~]` Message retrieval trusts query sender/receiver IDs without ownership binding | Role is checked, but conversation participant binding is weak | `Admin/get_messages.php:10`, `Admin/get_messages.php:23`, `Legal/get_messages.php:10`, `Finance/get_messages.php:10`, `Claimant/get_messages.php:10` | Enforce that one side of conversation equals current session user ID |
| RSK-07 | `[~]` SQL safety is mixed (prepared in some modules, interpolated in others) | Injection risk surface larger than necessary | Prepared examples: `auth.php:8`, `Admin/mark_notification_read.php:31`; interpolated examples: `Claimant/form.php:19`, `Legal/claims.php:23` | Standardize all dynamic queries to prepared statements |

## 13) Panel-Defense One-Liners (Use Verbatim if Needed)
- The system enforces role separation at entry, session, and endpoint layers, so claimant and bank-staff actions cannot be mixed accidentally.
- Claim submission uses both structured disbursement validation and document OCR verification before a claim can enter formal processing.
- Required OCR failures trigger full rollback of claim creation, preventing bad claims from entering legal/finance queues.
- Routing is load-aware and assignment-safe: with multiple officers, work is balanced; with no available officer, escalation is generated.
- Legal and finance transitions are state-locked and assignee-locked, so claims cannot jump stages or be decided by unauthorized staff.
- Every critical action is activity-logged and reportable with settlement details, enabling end-to-end auditability.
- User-facing error handling is explicit and persistent (message log + notifications), reducing hidden failure states.
