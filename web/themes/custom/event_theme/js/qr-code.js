(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var wrapper = document.getElementById('qr-code-wrapper');
    if (!wrapper) { return; }
    var uuid = wrapper.getAttribute('data-uuid');
    if (!uuid || typeof QRCode === 'undefined') { return; }
    new QRCode(document.getElementById('qr-code-canvas'), {
      text: uuid,
      width: 260,
      height: 260,
      colorDark: '#0c1135',
      colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.M
    });
  });
}());
