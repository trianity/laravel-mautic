# Release checklist

No release or Packagist submission is part of repository initialization.

1. Finish and review the intended milestone; update README, changelog and `.ai/STATE.md`.
2. Run `composer check` on the preferred stack; require a green CI matrix on the exact commit.
3. Validate dependencies with Composer audit; review findings instead of disabling checks.
4. Run opt-in real-server tests on a disposable, explicitly supported Mautic version.
5. Verify source archives contain source/config/license and omit test/AI/CI material.
6. Review public APIs, backwards compatibility and dependency licenses. The package
   is MIT; dependencies retain their own licenses. Check any inconsistent upstream
   per-file headers before copying upstream source or making distribution claims.
7. On explicit owner authorization, create the intended SemVer tag and release notes.
8. Owner submits `https://github.com/trianity/laravel-mautic` to Packagist and configures
   its GitHub update hook. Do not put a version field or Packagist token in composer.json.
9. Test a clean host install from the tagged Packagist version and publish migration notes.
