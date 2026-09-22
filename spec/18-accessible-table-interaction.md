# Accessible table interaction

Status: S19 implementation slice, 2026-09-22.

Visible cards and cards in the current participant's hand are keyboard-focusable semantic controls. Enter or Space performs the same safe face action as the pointer interaction, while a visible action menu remains available for movement and explicit face-up/down play. Hand cards expose both “Play face up” and “Play face down”; spectators and other participants retain only their authorized public projections.

Authorized face-up/hand projections may include the card definition's display name as a safe label. Face-down, private-to-another-player, deck and removed cards never include a definition name or protected asset path. The table surface remains scrollable on narrow viewports, controls retain visible `:focus-visible` styling, and touch targets are not dependent on double-click timing.

Acceptance requires contract coverage for safe labels and a browser check that keyboard focus, Enter/Space action, explicit hand face choice and narrow-width layout remain usable.
