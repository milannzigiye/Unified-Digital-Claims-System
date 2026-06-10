/* Shared profile page behavior */
(function () {
  function getRuleConfig(custom) {
    return Object.assign(
      {
        mode: "simple",
        minLength: 8,
        requireNumber: true,
        requireSpecialChar: false,
      },
      custom || {}
    );
  }

  function hasLower(value) {
    return /[a-z]/.test(value);
  }

  function hasUpper(value) {
    return /[A-Z]/.test(value);
  }

  function hasDigit(value) {
    return /\d/.test(value);
  }

  function hasSpecial(value) {
    return /[@$!%*?&]/.test(value);
  }

  window.togglePassword = function togglePassword(inputId) {
    var input = document.getElementById(inputId);
    if (!input) {
      return;
    }
    var icon = input.parentElement ? input.parentElement.querySelector("i") : null;
    if (input.type === "password") {
      input.type = "text";
      if (icon) {
        icon.classList.remove("bi-eye");
        icon.classList.add("bi-eye-slash");
      }
    } else {
      input.type = "password";
      if (icon) {
        icon.classList.remove("bi-eye-slash");
        icon.classList.add("bi-eye");
      }
    }
  };

  window.checkPasswordStrength = function checkPasswordStrength() {
    var passwordInput = document.getElementById("newPassword");
    var meter = document.getElementById("passwordStrength");
    if (!passwordInput || !meter) {
      return;
    }

    var password = passwordInput.value || "";
    var score = 0;
    if (password.length >= 8) score += 25;
    if (hasLower(password)) score += 25;
    if (hasUpper(password)) score += 25;
    if (hasDigit(password) || hasSpecial(password)) score += 25;

    meter.style.width = String(score) + "%";
    if (score < 50) {
      meter.className = "strength-meter strength-weak";
    } else if (score < 75) {
      meter.className = "strength-meter strength-medium";
    } else {
      meter.className = "strength-meter strength-strong";
    }
  };

  window.checkPasswordMatch = function checkPasswordMatch() {
    var newPassword = document.getElementById("newPassword");
    var confirmPassword = document.getElementById("confirmPassword");
    var matchElement = document.getElementById("passwordMatch");
    if (!newPassword || !confirmPassword || !matchElement) {
      return;
    }

    if (!newPassword.value || !confirmPassword.value) {
      matchElement.textContent = "";
      matchElement.className = "";
      return;
    }

    if (newPassword.value === confirmPassword.value) {
      matchElement.textContent = "Passwords match";
      matchElement.className = "text-success";
    } else {
      matchElement.textContent = "Passwords do not match";
      matchElement.className = "text-danger";
    }
  };

  window.confirmDeletion = function confirmDeletion() {
    return confirm(
      "WARNING: This action cannot be undone!\n\nAll your data including claims, messages, and account information will be permanently deleted.\n\nAre you absolutely sure you want to delete your account?"
    );
  };

  function wirePhotoInput() {
    var photoInput = document.querySelector('input[name="photo"]');
    if (!photoInput) {
      return;
    }
    photoInput.addEventListener("change", function (e) {
      var fileName = (e.target.files && e.target.files[0] && e.target.files[0].name) || "No file selected";
      var label = e.target.previousElementSibling;
      if (label) {
        label.textContent = "Upload profile photo (" + fileName + ")";
      }
    });
  }

  function validateSecurePassword(password, rules) {
    if (password.length < rules.minLength) {
      return "Password must be at least " + rules.minLength + " characters long.";
    }
    if (!hasLower(password) || !hasUpper(password)) {
      return "Password must contain at least one uppercase and one lowercase letter.";
    }
    if (rules.requireNumber && !hasDigit(password)) {
      return "Password must contain at least one number.";
    }
    if (rules.requireSpecialChar && !hasSpecial(password)) {
      return "Password must contain at least one special character (@$!%*?&).";
    }
    return "";
  }

  function initSimpleMode() {
    var form = document.querySelector("form");
    if (!form) {
      return;
    }
    form.addEventListener("submit", function (e) {
      var password = document.querySelector('input[name="password"]');
      var confirmPassword = document.querySelector('input[name="re_password"]');
      if (!password || !confirmPassword) {
        return;
      }
      if (password.value && password.value !== confirmPassword.value) {
        e.preventDefault();
        alert("Passwords do not match.");
      }
    });
  }

  function initSecureMode(rules) {
    var form = document.getElementById("passwordForm");
    if (!form) {
      return;
    }
    form.addEventListener("submit", function (e) {
      var newPasswordInput = document.getElementById("newPassword");
      var confirmPasswordInput = document.getElementById("confirmPassword");
      if (!newPasswordInput || !confirmPasswordInput) {
        return;
      }
      var newPassword = newPasswordInput.value || "";
      var confirmPasswordValue = confirmPasswordInput.value || "";

      var validationError = validateSecurePassword(newPassword, rules);
      if (validationError) {
        e.preventDefault();
        alert(validationError);
        return;
      }

      if (newPassword !== confirmPasswordValue) {
        e.preventDefault();
        alert("Passwords do not match. Please confirm your new password.");
      }
    });
  }

  window.initProfilePage = function initProfilePage(config) {
    var rules = getRuleConfig(config);
    wirePhotoInput();
    if (rules.mode === "secure") {
      initSecureMode(rules);
    } else {
      initSimpleMode();
    }
  };
})();
