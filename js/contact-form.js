document.addEventListener('DOMContentLoaded', function () {

  const form = document.querySelector('.contact-form');
  if(form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault(); // Предотвращаем обычное отправление формы
      const formData = new FormData(form);
      // Если валидация пройдена, отправляем форму через AJAX
      if (validateForm(form)) {
        fetch(contactFormData.ajaxUrl, {
          method: 'POST',
          body: formData
        })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              form.reset();
            }
            alert(data.data.message);
          })
          .catch(error => {
            console.error('Error submitting data:', error);
          });
      }
    });
  }


  // Пример валидации
  function validateForm(form) {

    // Валидация полей формы
    const name = form.querySelector('#name').value.trim();
    const email = form.querySelector('#email').value.trim();
    const message = form.querySelector('#message').value.trim();
    const errors = [];

    // Проверка поля имени
    if (name === '') {
      errors.push('Please enter your name.');
    }

    // Проверка поля email
    if (email === '') {
      errors.push('Please enter your email.');
    } else if (!validateEmail(email)) {
      errors.push('Please enter a valid email.');
    }
    // Функция для проверки корректности email
    function validateEmail(email) {
      const re = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
      return re.test(String(email).toLowerCase());
    }

    // Проверка поля сообщения
    if (message === '') {
      errors.push('Please enter a message.');
    }

    // Если есть ошибки, выводим их
    if (errors.length > 0) {
      alert(errors.join(' '));
      return;
    } else {
      return true
    }

  }

});