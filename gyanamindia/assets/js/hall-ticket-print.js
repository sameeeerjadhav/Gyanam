/**
 * Hall ticket printing — prints through a hidden same-page frame.
 * No pop-up window is opened, so browser pop-up blockers cannot interfere.
 */
(function () {
    'use strict';

    function waitForAssets(doc) {
        var waits = Array.prototype.map.call(doc.querySelectorAll('img'), function (img) {
            return img.complete ? Promise.resolve() : new Promise(function (res) {
                img.addEventListener('load', res);
                img.addEventListener('error', res);
            });
        });
        var link = doc.querySelector('link[rel="stylesheet"]');
        if (link) {
            waits.push(new Promise(function (res) {
                link.addEventListener('load', res);
                link.addEventListener('error', res);
            }));
        }
        return Promise.race([
            Promise.all(waits),
            new Promise(function (res) { setTimeout(res, 2500); })
        ]);
    }

    /**
     * @param {string} html   Hall ticket markup (one or more tickets)
     * @param {string} cssUrl Stylesheet URL for the A4 hall ticket layout
     */
    window.printHallTicketHtml = function (html, cssUrl) {
        if (!html || !String(html).trim()) {
            alert('Hall ticket content is not ready. Click Generate or Reprint first.');
            return;
        }

        var old = document.getElementById('htPrintFrame');
        if (old) old.parentNode.removeChild(old);

        var frame = document.createElement('iframe');
        frame.id = 'htPrintFrame';
        frame.setAttribute('aria-hidden', 'true');
        frame.style.cssText = 'position:fixed;right:0;bottom:0;width:210mm;height:297mm;border:0;opacity:0;pointer-events:none;z-index:-1;';
        document.body.appendChild(frame);

        var win = frame.contentWindow;
        var doc = win.document;
        doc.open();
        doc.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Hall Ticket - GIIT</title>'
            + '<base href="' + window.location.href.replace(/"/g, '&quot;') + '">'
            + '<link rel="stylesheet" href="' + String(cssUrl || '').replace(/"/g, '&quot;') + '">'
            + '</head><body class="ht-a4-print">' + html + '</body></html>');
        doc.close();

        waitForAssets(doc).then(function () {
            setTimeout(function () {
                try {
                    win.focus();
                    win.print();
                } catch (e) {
                    console.error('Hall ticket print failed:', e);
                    alert('Could not print the hall ticket. Please click Reprint again.');
                }
                setTimeout(function () {
                    if (frame.parentNode) frame.parentNode.removeChild(frame);
                }, 60000);
            }, 150);
        });
    };
})();
