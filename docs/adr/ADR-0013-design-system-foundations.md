# ADR-0013 — Design system foundations

**Status:** proposed (UI-001, awaiting architectural review)
**Date:** 2026-08-15

---

## Context

By the end of Wave 2 SaniTube had seven backend modules and no interface. The
next seven tickets each add screens. Whatever the first of them establishes —
how a colour is chosen, how a status is coloured, how a form reports an error —
becomes the convention twenty screens copy, correct or not.

So the design system is built and reviewed *before* the screens, which is the
whole reason UI-001 exists as a separate ticket.

## Decisions

### 1. Semantic tokens, not a colour scale

Components ask for `--color-surface`, never `--color-zinc-900`.

Dark mode then becomes a redefinition of the same names rather than a second
set of class names sprinkled through every template — which is the difference
between one file changing and fifty files being audited. The consequence to
accept is that a component cannot reach for an arbitrary shade; if it needs
one, the token set is missing something and that is worth noticing.

### 2. Near-neutral palette, one accent

SaniTube displays album artwork, waveforms and cover images all day. An
interface that is itself colourful competes with the material it exists to
show. Colour is therefore reserved for meaning — status, danger, the single
indigo accent — and everything structural is a grey.

The dark palette is **lifted, not inverted**: the same indigo at the same
lightness is unreadable on a dark ground.

### 3. Type scale named by role

`--text-page-title`, `--text-metric`, `--text-identifier` rather than `--text-lg`.

A "section title" becomes one decision made once instead of a size guessed at
per screen. Identifiers and metrics get tabular figures, because ISRCs and
durations sit in columns and proportional digits make a table look ragged.

### 4. Three theme states, class-based, applied before paint

Light, dark, and follow-the-system. A boolean cannot express the third, and a
user on automatic night mode forced to pick one gets the wrong theme for half
the day.

The class is set by a blocking inline script in the root view. Doing it in the
Vue app means the browser paints light and then swaps — the flash of white that
makes a dark interface feel broken.

The preference lives in `localStorage`, **not** on the server: the same person
on a bright laptop and a dark phone wants different answers.

### 5. Status colour is decided in one component

`StatusBadge` owns the map from status value to tone. Every module has its own
status enum, and without one place deciding what "in progress" looks like the
same idea ends up amber on one screen and blue on another.

The *label* always comes from the translations. Showing `WAITING_CAPABILITY` to
a user is showing them the database.

### 6. Native controls where the platform is already correct

`<select>` rather than a custom listbox. A hand-built dropdown re-implements
keyboard navigation, type-ahead, screen-reader semantics and the mobile picker,
and almost every one gets at least one wrong. The platform control is already
correct in six languages.

### 7. Accessibility decisions that are not negotiable

- **`:focus-visible`, never `:focus`.** A ring on every mouse click is what
  makes people delete focus styles, and deleting them is what makes an
  interface unusable by keyboard.
- **Dialogs move focus in, trap it, and return it.** All three, or a keyboard
  user opens a dialog and their focus is still behind it.
- **Real `<table>` semantics.** A screen reader announces "column 3 of 8,
  Duration" from the markup; no amount of ARIA on divs reproduces that.
- **`role` chosen by severity.** Only danger and warning interrupt a screen
  reader. Marking every notice as an alert is how users learn to ignore them.
- **`prefers-reduced-motion` is honoured.** Vestibular disorders are not a
  preference.

### 8. Null renders as an em dash, never as zero

`MetricCard` takes `number | null`. "No failed jobs" and "we could not count
the failed jobs" are different facts, and a dashboard showing `0` for both
hides an outage. This is the interface half of a rule the backend already
follows.

### 9. Translations are shared from the server, flat and dotted

Resolved server-side for the active locale and sent with every page. No second
request for a language pack, no duplicate copy in the bundle, and no
possibility of the interface being in one language while the server thinks it
is in another.

The map is deliberately **flat with literal dotted keys**, so `trans('ui.actions.save')`
is one lookup rather than a walk down an object. A missing key returns the key
itself: blank text looks like a layout bug and gets ignored, `ui.actions.save`
on screen is unmistakable and gets fixed.

### 10. Navigation is built on the server

Which sections a person may reach depends on their role, and a client-side list
filtered in JavaScript is a list an attacker reads in full before the filter
runs.

Sections without a screen are returned as `available: false` rather than
omitted. A navigation that grows as features land is disorienting; one that
shows the shape of the product and marks what is not ready is stable to design
against.

**`available` is presentation only.** It is never the authorisation — that is
the route middleware — and a disabled link is not a permission check.

### 11. Pages are resolved eagerly

One slightly larger bundle fetched once beats a network round trip on every
navigation. SaniTube is an internal tool behind a login, frequently on modest
shared hosting, where code-splitting an admin interface feels like latency
rather than like speed.

## A dependency constraint worth recording

`@vitejs/plugin-vue@5` declares a peer range of Vite `^5 || ^6`. The project is
on Vite 7, so npm refused the install.

**The resolution was to use `@vitejs/plugin-vue@6`, which actually supports
Vite 7 — not `--force` or `--legacy-peer-deps`.** Forcing it would have
installed a plugin against a Vite major it was never tested on, and the failure
mode of that is a build that works until it silently does not. A peer conflict
is information about compatibility; overriding it discards the information and
keeps the incompatibility.

The same applies to `vue-tsc@3`, which is the release that pairs with the
current TypeScript.

This will recur: the frontend toolchain moves faster than the peer ranges
declared against it. The rule is to find the version that supports the stack,
and if none exists, to say so rather than force one.

## Consequences

- Twenty screens can now be built without re-deciding any of the above.
- A component needing a colour outside the token set is a signal, not a licence
  to add a hex value.
- The translation gate makes an untranslated string a CI failure, so the six
  locales cannot drift apart silently.
- Changing the accent, the radius scale or the dark palette is a single-file
  change.
