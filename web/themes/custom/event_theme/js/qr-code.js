(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var wrapper = document.getElementById('qr-code-wrapper');
    if (!wrapper) { return; }
    var uuid = wrapper.getAttribute('data-uuid');
    var email = wrapper.getAttribute('data-email') || '';
    if (!uuid || typeof QRCode === 'undefined') { return; }
    var qrContent = JSON.stringify({ identity_uuid: uuid, email: email });
    new QRCode(document.getElementById('qr-code-canvas'), {
      text: qrContent,
      width: 260,
      height: 260,
      colorDark: '#0c1135',
      colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.M
    });
  });
}());
