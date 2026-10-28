# Documentation Style Guide

> **Status**: Active  
> **Updated**: 2026-10-27  
> **Version**: 0.0.0
> **Code Owner**: @dominikletica  

# Language & Tone
- Write in English, concise but descriptive.
- Use second-person for user guides (“You can…”).
- Avoid colloquialisms; prefer clear, actionable language.

# Structure
- Each document starts with an H1 title (`# Title`), followed by status metadata (see [Status Tags](#status-tags) for more).
- Use mixed-upper case for headings: `## Getting Started`, `### Error Handling`.
- Include a short intro paragraph before diving into detail.
- Sections should follow a logical order: background → prerequisites → steps → references.

# Formatting
- Respect `.editorconfig` when editing documentation. In Markdown, two trailing spaces intentionally force a line break; keep them when they are used in metadata blocks, lists, or block quotes that should render as separate lines on GitHub.
- Use markdown lists for steps (`1.`) and unordered bullets (`-`).
- Highlight commands with fenced code blocks (add language hint):
  ````
  ```bash
  whoami
  ```
  ````
- Inline code uses backticks (`` ` ``).
- Tables should have headers and alignment pipes.
- Block quotes (`>`) for callouts or notes; prefix important warnings using GitHub Markdown Notices (`> [!CAUTION]`, `> [!WARNING]`, `> [!IMPORTANT]`, `> [!TIP]` or `> [!NOTE]`).


# Status Tags
- Include metadata near the top (add two trailing spaces at the end of each line to force a linebreak):
  > **Status**: {Active|Omitted|Closed|Solved|Draft}  
  > **Updated**: {Y.m.d}  
  > **Version**: {Project_Version}
  > **Code Owner**: {Code_Owner}  

- Update the `Updated` field when changes occur.

# Cross-Referencing
- Link to other docs via relative paths.
- When referencing sections, use explicit anchors.

# Screenshots & Media
- Store images under `assets/`; include captions or context in the surrounding text.
- When referencing UI elements, describe the path (e.g., “Go to **Admin → Navigation Builder**”).
- Ensure screenshots show English UI unless documenting localization.

# Review Checklist
- Confirm table of contents (if present) matches headings.
- Verify links and image references.
- Ensure code samples are tested or clearly marked pseudocode.
- Keep docs aligned with current behaviour; log drift if immediate update is not possible.

Following this guide keeps the documentation clear, predictable, and easy to maintain—especially when multiple agents contribute in parallel.