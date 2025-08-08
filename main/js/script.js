document.addEventListener('DOMContentLoaded', () => {
  const buttons = document.querySelectorAll('.Statusbutton');

  buttons.forEach(button => {
    let status = parseInt(button.getAttribute('data-status'));
    if (isNaN(status)) {
      status = 0;
      button.setAttribute('data-status', status);
    }

    applyStatusStyle(button, status);

    button.addEventListener('click', () => {
      let current = parseInt(button.getAttribute('data-status'));
      let next = (current + 1) % 3;
      button.setAttribute('data-status', next);
      applyStatusStyle(button, next);
    });
  });
});

function applyStatusStyle(button, status) {
  button.classList.remove('red', 'blue', 'green');

  switch (status) {
    case 0:
      button.classList.add('red');
      break;
    case 1:
      button.classList.add('blue');
      break;
    case 2:
      button.classList.add('green');
      break;
  }
}
