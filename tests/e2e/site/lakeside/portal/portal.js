// Synthetic Lakeside applicant portal (practice only): a fake login and a three-step application kept in
// localStorage. Nothing is sent anywhere. Used by the browser-control smoke and for manual practice.
(function () {
    var SESSION = 'lakeside_portal_session';
    var APP = 'lakeside_portal_application';

    window.LakesidePortal = {
        loggedIn: function () {
            return !!localStorage.getItem(SESSION);
        },
        requireLogin: function () {
            if (!this.loggedIn()) {
                location.replace('login.html?next=' + encodeURIComponent(location.pathname.split('/').pop()));
                return false;
            }
            var who = document.getElementById('who');
            if (who) who.textContent = 'Signed in as ' + localStorage.getItem(SESSION);
            return true;
        },
        load: function () {
            try {
                return JSON.parse(localStorage.getItem(APP) || '{}');
            } catch (e) {
                return {};
            }
        },
        // Put saved answers back into the step's form, and save them again on "Save and continue".
        bindStep: function (formId, nextPage) {
            var form = document.getElementById(formId);
            var saved = this.load();
            Array.prototype.forEach.call(form.elements, function (el) {
                if (!el.name || !(el.name in saved)) return;
                if (el.type === 'checkbox') el.checked = saved[el.name] === true;
                else if (el.type === 'radio') el.checked = saved[el.name] === el.value;
                else el.value = saved[el.name];
            });
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var missing = Array.prototype.filter.call(form.elements, function (el) {
                    return el.required && !el.disabled && ((el.type === 'checkbox' && !el.checked) || (el.type !== 'checkbox' && !el.value));
                });
                var alert = document.getElementById('form-alert');
                if (missing.length > 0) {
                    alert.textContent = 'Please complete the required fields: ' + missing.map(function (el) {
                        var label = document.querySelector('label[for="' + el.id + '"]');
                        return label ? label.textContent.trim() : el.name;
                    }).join(', ') + '.';
                    alert.hidden = false;
                    return;
                }
                var data = window.LakesidePortal.load();
                Array.prototype.forEach.call(form.elements, function (el) {
                    if (!el.name) return;
                    if (el.type === 'checkbox') data[el.name] = el.checked;
                    else if (el.type === 'radio') { if (el.checked) data[el.name] = el.value; }
                    else data[el.name] = el.value;
                });
                localStorage.setItem(APP, JSON.stringify(data));
                location.assign(nextPage);
            });
        },
    };
})();
