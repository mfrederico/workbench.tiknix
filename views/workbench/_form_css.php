<?php /* The create and edit forms' cards (included by both). */ ?>
<style>
/* A card that IS the control: a <label> wrapping its input, so every pixel of it toggles.
   Colours come from Bootstrap 5.3's subtle/emphasis variables rather than fixed hex, so the
   chosen state reads correctly in both the light and dark themes. :has() lets the card react
   to its own input with no JS to fall out of sync with the input's real state.
   NOTE deliberately NOT .form-check — that class pairs padding-left:1.5rem on the container
   with margin-left:-1.5rem on the input, so padding the card floated the box outside its own
   left border. Flex does the layout instead. */
.wb-card {
    cursor: pointer; --wb: var(--bs-primary); --wb-rgb: var(--bs-primary-rgb);
    --wb-bg: var(--bs-primary-bg-subtle); --wb-border: var(--bs-primary-border-subtle); --wb-text: var(--bs-primary-text-emphasis);
    transition: background-color .15s ease-in-out, border-color .15s ease-in-out;
}
.wb-card.wb-go { --wb: var(--bs-success); --wb-rgb: var(--bs-success-rgb);
    --wb-bg: var(--bs-success-bg-subtle); --wb-border: var(--bs-success-border-subtle); --wb-text: var(--bs-success-text-emphasis); }
.wb-card:hover { border-color: var(--bs-secondary-border-subtle); }
.wb-card:has(input:checked) { background-color: var(--wb-bg) !important; border-color: var(--wb-border) !important; }
.wb-card:has(input:checked) .wb-card-title { color: var(--wb-text); }
.wb-card .form-check-input:checked { background-color: var(--wb); border-color: var(--wb); }
/* Keyboard users get the focus ring on the CARD, since that is what reads as the control. */
.wb-card:has(input:focus-visible) { box-shadow: 0 0 0 .25rem rgba(var(--wb-rgb), .25); border-color: var(--wb-border); }
.wb-card .form-check-input:focus { box-shadow: none; }
.wb-card-title { font-weight: 600; }
</style>
