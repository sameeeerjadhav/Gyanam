/**
 * 12-hour exam time helpers for renderTime12Picker() controls.
 * Element ids: {prefix}Hour, {prefix}Min, {prefix}AmPm
 */
(function (global) {
  function parse(hhmm) {
    var parts = String(hhmm || '10:00').split(/[:.]/);
    var h = parseInt(parts[0], 10);
    var m = parseInt(parts[1], 10);
    if (isNaN(h) || h < 0 || h > 23) h = 10;
    if (isNaN(m) || m < 0 || m > 59) m = 0;
    var ampm = h >= 12 ? 'PM' : 'AM';
    var h12 = h % 12;
    if (h12 === 0) h12 = 12;
    return { h12: h12, m: m, ampm: ampm };
  }

  function format24(h12, m, ampm) {
    h12 = parseInt(h12, 10);
    m = parseInt(m, 10);
    if (isNaN(h12) || h12 < 1 || h12 > 12) h12 = 12;
    if (isNaN(m) || m < 0 || m > 59) m = 0;
    var h = h12 % 12;
    if (String(ampm).toUpperCase() === 'PM') h += 12;
    return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
  }

  function set(prefix, hhmm) {
    var t = parse(hhmm);
    var hour = document.getElementById(prefix + 'Hour');
    var min = document.getElementById(prefix + 'Min');
    var ap = document.getElementById(prefix + 'AmPm');
    if (hour) hour.value = String(t.h12);
    if (min) min.value = String(t.m).padStart(2, '0');
    if (ap) ap.value = t.ampm;
  }

  function get(prefix, fallback) {
    var hour = document.getElementById(prefix + 'Hour');
    var min = document.getElementById(prefix + 'Min');
    var ap = document.getElementById(prefix + 'AmPm');
    if (!hour || !min || !ap) return fallback || '10:00';
    return format24(hour.value, min.value, ap.value);
  }

  global.Time12 = { parse: parse, format24: format24, set: set, get: get };
})(typeof window !== 'undefined' ? window : this);
