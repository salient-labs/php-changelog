## NAME

changelog - Generate a changelog from GitHub release notes

## SYNOPSIS

**`changelog`** \[*<u>option</u>*]... \[**`--`**] *<u>owner</u>*/*<u>repo</u>*...

## OPTIONS

- *<u>owner</u>*/*<u>repo</u>*...

  One or more GitHub repositories with release notes.

  The first repository passed to **`changelog`** is regarded as the primary
  repository.

- **`-n`**, **`--name`** *<u>name</u>*,...

  Name to use instead of *<u>owner</u>*/*<u>repo</u>* when referring to the repository.

  May be given once per repository.

- **`-r`**, **`--releases`**\[=*<u>value</u>*,...]

  Include releases found in the repository?

  **`changelog`** includes releases found in the primary repository by default.
  May be given once per repository.

  The default value is: `yes`

- **`-m`**, **`--missing`**\[=*<u>value</u>*,...]

  Report releases missing from the repository?

  **`changelog`** doesn't report missing releases by default. May be given once
  per repository.

  The default value is: `yes`

- **`-I`**, **`--include`** *<u>regex</u>*

  Regular expression for releases to include.

  If not given, all releases are included.

  Exclusions (**`-X/--exclude`**) are processed first.

- **`-X`**, **`--exclude`** *<u>regex</u>*

  Regular expression for releases to exclude.

  If not given, no releases are excluded.

- **`-F`**, **`--from`** *<u>tag_name</u>*

  Exclude releases before a given tag.

  This option has no effect if no release with the given *<u>tag_name</u>* is found.

- **`-T`**, **`--to`** *<u>tag_name</u>*

  Exclude releases after a given tag.

  If no release with the given *<u>tag_name</u>* is found, an empty changelog is
  generated.

- **`-h`**, **`--headings`** (`auto`|`secondary`|`all`)

  Specify headings to insert above release notes.

  In `auto` mode, headings are inserted above release notes contributed by
  repositories other than the primary repository, unless there is only one
  contributing repository for the release.

  In `secondary` mode, headings are always inserted above release notes
  contributed by repositories other than the primary repository.

  This option has no effect if **`-1/--merge`** is given.

  The default mode is: `auto`

- **`-1`**, **`--merge`**

  Merge release notes from all repositories.

  If this option is given, Markdown-style lists are merged and de-duplicated
  on a best-effort basis.

- **`-o`**, **`--output`** *<u>file</u>*

  Write output to a file.

  If *<u>file</u>* already exists, content before the first version heading is
  preserved.

- **`-f`**, **`--flush`**

  Flush cached release notes.

  GitHub responses are cached for 10 minutes. Use this option to replace
  responses cached on a previous run.

- **`-q`**, **`--quiet`**

  Only print warnings and errors.
