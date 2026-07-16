---
name: qloapps-module-development
description: Create, build, or modify QloApps modules. Covers hooks-first architecture, ObjectModel classes, admin and front controllers, Smarty templates, database operations, security validation, and deployment. Use for any QloApps module development task including creating new modules, adding hooks, building admin pages, writing database models, or preparing modules for distribution.
license: MIT
metadata:
  author: QloApps
---

# QloApps Module Development

Create production-ready feature modules for QloApps using hooks-first architecture.

## Quick Start

Create complete QloApps modules that:
- Work entirely within module folder (no core modifications)
- Use hooks for integration (hooks-first approach)
- Follow QloApps coding standards
- Include proper security, multilang, and permissions

See [architecture.md](./reference/architecture.md) for full module directory structure and required files.

---

## When to Use This Skill

Applies to:
- Creating new features for QloApps
- Building custom hotel management functionality
- Extending booking/reservation capabilities
- Adding admin dashboard features
- Creating customer-facing features

## When NOT to Use

- Payment modules → `payment-module-development` skill
- Statistics modules → `stats-module-development` skill

---

## Critical Development Principles

### Module-Only Development (Non-Negotiable)

All module work stays inside `modules/{modulename}/` folder.

NEVER:
- Modify core files directly
- Create files outside module folder
- Change existing QloApps files

ALWAYS:
- Work within your module directory
- Use hooks for integration
- Keep module self-contained

**Exception**: Only if absolutely no alternative exists, document root-level file requirements in README.md for manual user placement.

---

### Hooks-First Architecture

Priority order:
1. Use existing hooks (90% of cases)
2. Create custom hooks (when existing hooks don't work)
3. Use overrides (last resort — can conflict with other modules)

See [hooks-system.md](./reference/hooks-system.md) for hook registration patterns, common hooks reference, custom hook creation, and override guidelines.

---

## Skill Components

Reference guides for each area of module development:

- [architecture.md](./reference/architecture.md) — Module directory structure, required files, database setup
- [coding-conventions.md](./reference/coding-conventions.md) — Prefix rules, naming standards, file organization
- [hooks-system.md](./reference/hooks-system.md) — Hook registration, common hooks, custom hooks, overrides
- [models-repositories.md](./reference/models-repositories.md) — ObjectModel classes, CRUD, multilang/multishop
- [controllers.md](./reference/controllers.md) — Admin/front controllers, form handling, permissions
- [templates-views.md](./reference/templates-views.md) — Smarty templates, CSS/JS organization, asset loading
- [database-operations.md](./reference/database-operations.md) — Query patterns, security, hotel permission checks
- [security-validation.md](./reference/security-validation.md) — SQL injection, XSS, CSRF, input validation
- [deployment.md](./reference/deployment.md) — Install/uninstall, upgrades, versioning, distribution

---

## Quick Reference

### Module Creation Checklist
- [ ] Choose module name and calculate prefix
- [ ] Create module folder structure
- [ ] Add main module class file
- [ ] Add mandatory files (LICENSE.md, README.md, CHANGELOG.txt, index.php)
- [ ] Define constants in define.php
- [ ] Create database classes (if needed)
- [ ] Implement install/uninstall
- [ ] Register hooks (hooks-first!)
- [ ] Create controllers (if needed)
- [ ] Create templates
- [ ] Add CSS/JS files (qlo_* naming)
- [ ] Implement security measures
- [ ] Add multilanguage support
- [ ] Test thoroughly
- [ ] Create upgrade scripts
- [ ] Push to Git

See [coding-conventions.md](./reference/coding-conventions.md) for prefix calculation rules and naming standards.
See [security-validation.md](./reference/security-validation.md) for input escaping, CSRF, and XSS prevention patterns.

---

## Development Workflow

1. **Plan** — Define module purpose, calculate prefix, identify hooks, design database schema, plan multilang requirements
2. **Setup** — Create folder structure, main module file, mandatory files (LICENSE.md, README.md, CHANGELOG.txt, index.php), define.php with constants, Git repository
3. **Database** — Create database class, ObjectModel classes, install SQL, multilang field support
4. **Develop** — Implement install/uninstall, register hooks, create controllers, build templates with escaping, add CSS/JS, implement security and permission checks
5. **Test** — Test all features, multiple languages, different employee permissions
6. **Deploy** — Update README.md and CHANGELOG.txt, document manual steps, create upgrade scripts, push to Git

---

## Module Template

See [architecture.md](./reference/architecture.md) for the complete module class template with constructor, install/uninstall, and hook methods. Reference implementation: `modules/hotelreservationsystem/hotelreservationsystem.php`

---

## Common Pitfalls

1. **Modifying core files directly** — Use hooks instead. If hooks don't work, create custom hook and document placement in README.
2. **HTML in PHP files** — All HTML goes in .tpl files, load via templates.
3. **Forgetting prefix rules** — Follow strict prefix rules in [coding-conventions.md](./reference/coding-conventions.md).
4. **No SQL escaping** — Always use `(int)` for IDs and `pSQL()` for strings.
5. **Skipping permission checks** — Check `$this->tabAccess` in admin controllers and `HotelBranchInformation::addHotelRestriction()` for hotel-specific data.

---

## Validation Checklist

Verify before deployment:

### Functionality
- [ ] Module installs without errors
- [ ] Module uninstalls cleanly (removes all data)
- [ ] All features work as expected
- [ ] No errors in QloApps logs

### Code Quality
- [ ] No HTML in PHP files
- [ ] No CSS/JS in TPL files
- [ ] All variables in camelCase
- [ ] Prefix rules followed correctly
- [ ] Yoda conditions applied where suitable
- [ ] Comments added for complex logic
- [ ] License headers on all files
- [ ] index.php in every folder

### Security
- [ ] All inputs validated and type-cast
- [ ] SQL queries use pSQL() for strings
- [ ] Templates use {$var|escape:'html':'UTF-8'}
- [ ] Front ajax uses validation tokens
- [ ] Employee permissions checked
- [ ] Hotel access permissions checked (if applicable)

### Database
- [ ] Table names use _DB_PREFIX_ and module prefix
- [ ] Queries only select needed fields (no SELECT *)
- [ ] All query variables properly type-cast
- [ ] Tables created in install, dropped in uninstall
- [ ] Multilang data handled for new languages

### Multilanguage
- [ ] All user-facing text uses $this->l()
- [ ] Multilang fields supported in forms
- [ ] Tested with multiple languages
- [ ] Mail templates created for all languages

### Files & Structure
- [ ] LICENSE.md present (OSL-3.0)
- [ ] README.md complete with all documentation
- [ ] CHANGELOG.txt updated
- [ ] config.xml present
- [ ] logo.png and logo.gif present
- [ ] All file/folder names follow conventions

### Testing
- [ ] Tested with different employee roles
- [ ] Tested with multilanguage
- [ ] Tested with multishop (if applicable)
- [ ] All edge cases covered

### Deployment
- [ ] Upgrade script template created
- [ ] Git repository updated

---

## Reference Files

Reference these existing implementations:

| File | Purpose | Key Concepts |
|------|---------|--------------|
| `modules/hotelreservationsystem/hotelreservationsystem.php` | Main module class | Structure, hooks, install/uninstall |
| `modules/hotelreservationsystem/define.php` | Module constants | Constant definition pattern |
| `modules/hotelreservationsystem/classes/` | ObjectModel classes | Database models, CRUD operations |
| `modules/hotelreservationsystem/classes/HotelReservationSystemDb.php` | Database class | Table creation, install/uninstall |
| `modules/hotelreservationsystem/controllers/admin/` | Admin controllers | CRUD, forms, permissions |

---

## Troubleshooting

1. **Module won't install** — Check database table creation SQL, verify hook registration, ensure `parent::install()` is called first.
2. **Hooks not firing** — Verify hook is registered in `install()`, method name matches `hook{HookName}`, re-install module if needed.
3. **Override not working** — Delete `cache/class_index.php`, verify class extends `{ClassName}Core`, check file is in correct override folder.

---

## Additional Resources

- QloApps DevDocs: https://devdocs.qloapps.com/
