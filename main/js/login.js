// 規約: クラス=PascalCase, 変数=camelCase
class LoginForm {
  constructor(form) {
    this.form = form;
    this.isSubmitting = false;
    this.bind();
  }
  bind() {
    this.form.addEventListener('submit', (e) => {
      if (this.isSubmitting) { e.preventDefault(); return; } // 多重送信防止
      const userId = this.form.elements.userId.value.trim();
      this.form.elements.userId.value = userId;
      this.isSubmitting = true;
    });
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('LoginForm');
  if (form) new LoginForm(form);
});
