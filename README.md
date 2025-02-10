# DOI Prefill

Allows users create Drupal nodes with data retrieved from Crossref.

## Installation

Install as
[usual](https://www.drupal.org/docs/extending-drupal/installing-modules).

## Configuration

The form at `admin/config/system/doi_field_setting`s allows you to map the fields returned by Crossref to fields 
in your Islandora installation e.g. Crossref's `Contributors` field can be mapped to `field_contributor`, or `field_linked_agent`.

You may optionally map the Crossref genre fields to your own taxonomy term e.g. `journal-article` to `Journal Article`

## Usage

After installing and enabling and configuring the module add a link to `doi-prefill/doi-prepopulate` in your  [Shortcuts menu](admin/config/user-interface/shortcut/manage/default/customize)

Navigate to the form where you can enter a DOI, a collection for your new node, and choose whether you'd like to be taken the node's edit form or be returned to the same page.



## Maintainers
Current maintainers:

* [Robertson Library](https://library.upei.ca/)

## License
[GPLv3](http://www.gnu.org/licenses/gpl-3.0.txt)
