/**
 * Resume page script: PDF download.
 *
 * The color theme and its toggle come from the shared theme controller (/theme.js),
 * which fills the [data-theme-toggle] slots in the resume markup.
 */
(function () {
  function wireDownloads() {
    var downloadButtons = document.querySelectorAll('[data-download-resume]');
    downloadButtons.forEach(function (downloadBtn) {
      downloadBtn.addEventListener('click', function () {
        var link = document.createElement('a');
        var variant = downloadBtn.getAttribute('data-variant');
        link.href = '/resume/download-pdf.php' + (variant ? '?variant=' + encodeURIComponent(variant) : '');
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wireDownloads);
  } else {
    wireDownloads();
  }
})();
