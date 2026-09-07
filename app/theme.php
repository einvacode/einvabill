<?php
/**
 * Light / dark theme.
 *
 * The choice lives in a cookie so PHP can stamp data-theme on <html> before the
 * first byte of CSS: switching it in JavaScript alone makes the page flash the
 * wrong colours on every load. Three modes are stored — light, dark, and system
 * (follow the operating system) — and "system" is resolved by a small inline
 * script that runs before the stylesheets.
 *
 * Only the staff app and the login page follow it. The landing page and the
 * customer portal stay light on purpose.
 */

const THEME_COOKIE = 'eb_theme';

/** Stored preference: 'light', 'dark' or 'system'. */
function theme_mode(): string {
    $m = $_COOKIE[THEME_COOKIE] ?? 'system';
    return in_array($m, ['light', 'dark', 'system'], true) ? $m : 'system';
}

/**
 * The data-theme attribute for <html>. Empty for "system": the inline script
 * fills it in from prefers-color-scheme before anything is painted.
 */
function theme_attr(): string {
    $m = theme_mode();
    return $m === 'system' ? '' : ' data-theme="' . $m . '"';
}

/** Inline boot script. Must be the first thing in <head>, before the stylesheets. */
function theme_boot_script(): string {
    return '<script>(function(){var m=document.cookie.match(/(?:^|; )' . THEME_COOKIE . '=(light|dark|system)/);'
        . 'var mode=m?m[1]:"system";'
        . 'var dark=mode==="dark"||(mode==="system"&&window.matchMedia("(prefers-color-scheme: dark)").matches);'
        . 'var r=document.documentElement;r.setAttribute("data-theme",dark?"dark":"light");r.setAttribute("data-theme-mode",mode);})();</script>';
}
