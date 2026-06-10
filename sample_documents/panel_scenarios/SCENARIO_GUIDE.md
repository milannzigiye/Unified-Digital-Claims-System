# Panel Demo Scenarios

Claimant baseline for every scenario: Milan Nzigiye, male, ID 23866, residing in Kigali, Gasabo. These are demo records and generated documents for panel presentation and system testing only.

## S1: Milan Claims As Surviving Spouse

Milan Nzigiye walks into the system as the surviving husband of Nadia Uwera. Nadia was a Bank of Kigali customer with a savings account. There is no dispute about the family route: Milan proves who he is, proves Nadia passed away, and proves the marriage. The demo shows the cleanest path from claimant intake to legal confirmation and finance settlement.

### Form Setup
| Field | Value |
|---|---|
| Claimant | Milan Nzigiye |
| Claimant ID | 23866 |
| Claimant Gender | Male |
| Claimant Residence | Kigali, Gasabo |
| Deceased | Nadia Uwera |
| Deceased ID | 119880100000001 |
| Date of Death | 2026-02-12 |
| Marital Status | MARRIED |
| Spouse | Milan Nzigiye |
| Claimant Relationship | SPOUSE |
| Children Status | NONE |
| Will Exists | NO |
| Asset | Savings account BK-SA-01001, RWF 1,250,000 |
| Settlement | BK account transfer to Milan Nzigiye, 010-23866-01 |

### Generated Upload Documents
| File | Upload Field |
|---|---|
| `S1_deceased_death_certificate_nadia_uwera.jpg` | Deceased Death Certificate |
| `S1_claimant_id_milan.jpg` | Claimant ID Document |
| `S1_marriage_certificate_milan_nadia.jpg` | Marriage Certificate |

## S2: Milan Claims As Child Of A Single Parent

Milan is not claiming as a spouse or representative. His mother, Claudine Mukamana, raised him as a single parent and later passed away with a BK current account. The system has to prove two things clearly: Claudine was single, and Milan is her child. This scenario is easy to defend because every required document supports one direct question.

### Form Setup
| Field | Value |
|---|---|
| Claimant | Milan Nzigiye |
| Claimant ID | 23866 |
| Claimant Gender | Male |
| Claimant Residence | Kigali, Gasabo |
| Deceased | Claudine Mukamana |
| Deceased ID | 119900200000002 |
| Date of Death | 2026-01-28 |
| Marital Status | SINGLE |
| Spouse | Not applicable |
| Claimant Relationship | CHILD |
| Children Status | HAS_CHILDREN |
| Will Exists | NO |
| Asset | Current account BK-CA-02002, RWF 820,000 |
| Settlement | BK account transfer to Milan Nzigiye, 010-23866-01 |

### Generated Upload Documents
| File | Upload Field |
|---|---|
| `S2_deceased_death_certificate_claudine.jpg` | Deceased Death Certificate |
| `S2_claimant_id_milan.jpg` | Claimant ID Document |
| `S2_single_status_certificate_claudine.jpg` | Proof of Single Status |
| `S2_child_birth_certificate_milan.jpg` | Child Birth Certificate / Proof of Filiation |

## S3: Milan Claims After Both Parents Are Deceased

Milan lost his father, Jean Claude Habimana. Jean Claude had been married to Beatrice Mukamana, but Beatrice died before him. Milan is therefore not bypassing a living spouse. He is showing that the spouse route is closed and that his child route is the active path. This scenario is strong for the panel because it proves the system can read family hierarchy without guessing.

### Form Setup
| Field | Value |
|---|---|
| Claimant | Milan Nzigiye |
| Claimant ID | 23866 |
| Claimant Gender | Male |
| Claimant Residence | Kigali, Gasabo |
| Deceased | Jean Claude Habimana |
| Deceased ID | 1197870012345678 |
| Date of Death | 2026-03-18 |
| Marital Status | WIDOWED |
| Spouse | Beatrice Mukamana |
| Claimant Relationship | CHILD |
| Children Status | HAS_CHILDREN |
| Will Exists | NO |
| Asset | Fixed deposit BK-FD-00488210, RWF 5,750,000 |
| Settlement | BK account transfer to Milan Nzigiye, 010-23866-01 |

### Generated Upload Documents
| File | Upload Field |
|---|---|
| `S3_deceased_death_certificate_jean_claude.jpg` | Deceased Death Certificate |
| `S3_claimant_id_milan.jpg` | Claimant ID Document |
| `S3_marriage_certificate_jean_beatrice.jpg` | Marriage Certificate |
| `S3_spouse_death_certificate_beatrice.jpg` | Spouse Death Certificate |
| `S3_child_birth_certificate_milan.jpg` | Child Birth Certificate / Proof of Filiation |

## S4: Milan Claims As Brother Named In A Will

Milan is the brother of Eric Nzigiye. Eric was divorced, had no children in the claim record, and left a written will naming Milan as the beneficiary of a BK investment account. This is not an automatic approval case. The system accepts the claim, captures the will, and gives Legal a clear reason to review the beneficiary path before Finance can settle.

### Form Setup
| Field | Value |
|---|---|
| Claimant | Milan Nzigiye |
| Claimant ID | 23866 |
| Claimant Gender | Male |
| Claimant Residence | Kigali, Gasabo |
| Deceased | Eric Nzigiye |
| Deceased ID | 119870400000004 |
| Date of Death | 2026-04-06 |
| Marital Status | DIVORCED |
| Spouse | Not applicable |
| Claimant Relationship | FULL_SIBLING |
| Children Status | NONE |
| Will Exists | YES |
| Asset | Investment account BK-INV-04004, USD 3,200 |
| Settlement | Other bank transfer to Milan Nzigiye |

### Generated Upload Documents
| File | Upload Field |
|---|---|
| `S4_deceased_death_certificate_eric.jpg` | Deceased Death Certificate |
| `S4_claimant_id_milan.jpg` | Claimant ID Document |
| `S4_relationship_proof_sibling.jpg` | Supporting Relationship Certificate |
| `S4_will_copy_eric_names_milan.jpg` | Copy of Will |

## S5: Milan Acts For Other Siblings

Milan is one of Diane Nzigiye's siblings, but he is not pretending to be the only person involved. His brother and sister authorize him to submit the claim and coordinate the process. This scenario is useful for the panel because it shows the system demanding authority when Milan acts for other heirs instead of silently letting him control the whole claim.

### Form Setup
| Field | Value |
|---|---|
| Claimant | Milan Nzigiye |
| Claimant ID | 23866 |
| Claimant Gender | Male |
| Claimant Residence | Kigali, Gasabo |
| Deceased | Diane Nzigiye |
| Deceased ID | 119890500000005 |
| Date of Death | 2026-02-23 |
| Marital Status | DIVORCED |
| Spouse | Not applicable |
| Claimant Relationship | FULL_SIBLING |
| Acting on Behalf | YES |
| Other heirs | Alice Nzigiye; Patrick Nzigiye |
| Children Status | NONE |
| Will Exists | NO |
| Asset | Shares account BK-SH-05005, RWF 2,100,000 |
| Settlement | Hold pending final instruction until Finance validates split |

### Generated Upload Documents
| File | Upload Field |
|---|---|
| `S5_deceased_death_certificate_diane.jpg` | Deceased Death Certificate |
| `S5_claimant_id_milan.jpg` | Claimant ID Document |
| `S5_relationship_proof_sibling.jpg` | Supporting Relationship Certificate |
| `S5_representative_authority_family_resolution.jpg` | Authority Document |

## S6: Milan Claims As Brother With Fallback Single Evidence

Milan is the half-brother of Olivier Niyonsenga. Olivier died without a spouse, children, or will, but the formal single certificate is not available during intake. Milan submits a local attestation instead. The system can still accept the claim, but it marks the path for manual legal review because fallback evidence is weaker than a formal certificate.

### Form Setup
| Field | Value |
|---|---|
| Claimant | Milan Nzigiye |
| Claimant ID | 23866 |
| Claimant Gender | Male |
| Claimant Residence | Kigali, Gasabo |
| Deceased | Olivier Niyonsenga |
| Deceased ID | 119910600000006 |
| Date of Death | 2026-05-02 |
| Marital Status | SINGLE |
| Spouse | Not applicable |
| Claimant Relationship | HALF_SIBLING |
| Children Status | NONE |
| Will Exists | NO |
| Asset | Fixed deposit BK-FD-06006, RWF 3,900,000 |
| Settlement | BK account transfer to Milan Nzigiye, 010-23866-01 |

### Generated Upload Documents
| File | Upload Field |
|---|---|
| `S6_deceased_death_certificate_olivier.jpg` | Deceased Death Certificate |
| `S6_claimant_id_milan.jpg` | Claimant ID Document |
| `S6_relationship_proof_half_sibling.jpg` | Supporting Relationship Certificate |
| `S6_fallback_single_status_attestation.jpg` | Fallback Single-Status Attestation |
