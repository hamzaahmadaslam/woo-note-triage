# Changelog

## Unreleased

- Phone, card and account numbers written with other digits, such as the full-width digits typed with Japanese and
  Chinese input methods or Arabic-Indic digits, are now replaced with `<number>` before a note is sent. Before, only
  the digits 0 to 9 were counted, so these numbers were sent as typed. Full-width dots, slashes, dashes, brackets
  and plus signs now join the digits of a number as their ASCII forms do.
- Invisible tag characters (U+E0000 to U+E007F), which can carry text that a model reads but a person never sees,
  and the Arabic letter mark are removed from notes before they are sent.

## 1.0.0 - 2026-09-26

First release.
