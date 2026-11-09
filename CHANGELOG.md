# AI Mapping

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
