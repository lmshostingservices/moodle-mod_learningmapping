# AI Mapping

## [1.0.61] - 2026-11-11
- Course Builder tells the service each lesson's outcomes in full (code and text), not only their codes. Before this, outcomes with codes such as "LO4" were often linked to one activity only, because the service could not match the code to the outcome row.

## [1.0.60] - 2026-11-10
- Course Builder requests carry a stable `idempotencyKey` and the `attempt` number, so a retry after a timeout returns the job already running instead of charging again.

## [1.0.59] - 2026-11-10

- Course Builder mappings start with a locked outcome column ("Elements & Performance Criteria" for VET, "Learning outcomes" otherwise), as in a mapping made by hand. Before this the first activity column doubled as the outcome column, which hid the outcome of every row mapped to that activity.
- A mapping built by Course Builder no longer shows the setup steps as work still to do. They are under "Rebuild the mapping", with a note that the table is ready to edit.
- "Activity columns" no longer counts the outcome column.
- Hidden activities are left out of the mapping.
- A generation that times out, or hits a busy or unavailable provider, is retried automatically.

## [1.0.58] - 2026-11-09
- Limit Course Builder mapping rows to the current course unit's teacher-provided outcomes.
- Exclude the course announcements forum from activity columns and preserve planned outcome-to-activity links.

## [1.0.57] - 2026-11-07
- Restrict outcomes to the current course unit, include native activity content and enforce planned outcome-to-activity links.
- Preserve AI-suggested links while adding any planned links missing from the matrix.

## 1.0.55
- Add native Course Builder generation from saved program outcomes and course activities, with native mapping persistence and the fixed 100-credit estimate.
- Route existing mapping saves through the same module-owned storage helper without clearing omitted legacy fields.

## 1.0.54
- Add the AI Course Builder generator adapter.

## 1.0.53
- Add framework selection and framework-specific curriculum fields for non-VET mapping.
- Preserve the existing VET/TGA flow as the default, including its existing save format.
- Add non-VET outcome-to-activity mapping with introduced, practised, or assessed coverage labels.
- Add Moodle upgrade and backup/restore support for framework fields.
