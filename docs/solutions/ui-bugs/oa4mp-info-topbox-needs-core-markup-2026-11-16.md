---
title: The Named configuration warning rendered as side-by-side columns because the page used core's info-box class without its markup
date: 2026-11-16
category: ui-bugs
module: Oa4mpClient plugin (Named configuration confirmation view)
problem_type: ui_bug
component: rails_view
severity: low
symptoms:
  - "On the Named configuration confirmation page, the two warning paragraphs in the yellow box rendered side by side as narrow columns"
  - "The CONFIRM and Cancel buttons below the box touched each other"
root_cause: logic_error
resolution_type: code_fix
tags: [comanage, co-info-topbox, flexbox, view, bootstrap, cancelbutton, named-config, layout]
---

# The Named configuration warning rendered as side-by-side columns because the page used core's info-box class without its markup

## Problem

When an admin chose a Named configuration, the confirmation page (`View/Oa4mpClientCoNamedConfigs/confirm.ctp`) showed its two warning paragraphs next to each other as narrow columns, and the CONFIRM and Cancel buttons were pressed together. Fixed by cilogon/Oa4mpClient#85.

## Root cause

**The info box is a flex container.** COmanage core styles `.co-info-topbox` with `display: flex; align-items: center` (core `webroot/css/co-base.css`, the `.co-info-topbox` rule). Core always puts exactly two children in it, an icon and one text element:

```html
<div class="co-info-topbox">
  <em class="material-icons">info</em>
  <div class="co-info-topbox-text">...</div>
</div>
```

(for example core `View/OrganizationSources/retrieve.ctp`). The plugin page put its two `<p>` elements directly in the box, so each became a flex item, and they were laid out in a row.

**Nothing separated the buttons.** `Form->submit()` wraps its button in `<div class="submit">`, and the Cancel link followed straight after it. Core turns `.cancelbutton` into a jQuery UI button, but adds no margin. Where core places buttons next to each other, it adds Bootstrap 4 spacing classes such as `mr-1` (core `View/CoInvites/buttons.inc`). Core loads Bootstrap on every page.

## Solution

- Put an `<em class="material-icons">info</em>` and one `<div class="co-info-topbox-text">` inside the box, with the paragraphs in that div.
- Print CONFIRM with `Form->submit(..., array('div' => false))`, so it stays inline, and give Cancel the class `ml-2 cancelbutton`.

`NamedConfigApplyRemoveTest::testConfirmationPageUsesCoreInfoBoxAndSpacedButtons` checks the view source for both. That proves the markup, not the appearance, so a layout change still needs a look in a browser.

## Prevention

When a plugin view uses a core layout class, copy core's whole markup for it, not just the class name. `co-info-topbox` in particular is a flex row that expects an icon and a single text element; anything else placed directly inside it becomes another column.
