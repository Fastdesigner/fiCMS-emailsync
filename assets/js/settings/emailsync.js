function emailsync__language(key) {
	return typeof settings__language_apply === 'function' ? settings__language_apply(key) : key;
}

function emailsync__field_value(field) {
	if (!field) return '';
	if (typeof forms__read_input !== 'function') return field.value || '';
	let value = forms__read_input(field);
	return value ? String(value.value || '') : '';
}

function emailsync__connection_state(dropdown, state, message) {
	if (!dropdown) return false;
	dropdown.setAttribute('data-notify',state);
	let summary = dropdown.querySelector(':scope > summary'), status = dropdown.querySelector('[data-emailsync-connection-status]');
	if (summary) {
		summary.setAttribute('data-notify',state);
		let icon = summary.querySelector('[data-systemicon]');
		if (icon) icon.setAttribute('data-systemicon',state);
		let subtitle = summary.querySelector('font');
		if (subtitle) subtitle.textContent = message;
	}
	if (status) {
		status.setAttribute('data-notify',state);
		status.textContent = message;
	}
	return true;
}

function emailsync__domain_from_username(input) {
	if (!input) return false;
	let form = input.closest('form'), domain = form ? form.querySelector('[data-emailsync-domain]') : false;
	if (!domain || String(domain.value || '').trim() !== '') return false;
	let username = String(input.value || '').trim(), position = username.lastIndexOf('@');
	if (position < 1 || position >= username.length - 1) return false;
	domain.value = username.substring(position + 1).toLowerCase();
	domain.dispatchEvent(new Event('change',{bubbles:true}));
	return true;
}

function emailsync__connection_data(dropdown) {
	let side = dropdown.getAttribute('data-emailsync-connection') || '', form = dropdown.closest('form'), data = {};
	if (!form || (side !== 'source' && side !== 'destination')) return false;
	['host','port','security','username','password'].forEach(field => {
		let input = form.querySelector('[name="'+side+'_'+field+'"]');
		data[field] = emailsync__field_value(input);
	});
	data.complete = data.host.trim() !== '' && data.port.trim() !== '' && data.security.trim() !== '' && data.username.trim() !== '' && (data.password.trim() !== '' || dropdown.getAttribute('data-emailsync-has-secret') === '1');
	return data;
}

function emailsync__connection_test(dropdown) {
	let values = emailsync__connection_data(dropdown);
	if (!values || !values.complete) return emailsync__connection_state(dropdown,'warning',emailsync__language('_emailsync_connection_pending'));
	let side = dropdown.getAttribute('data-emailsync-connection'), sequence = parseInt(dropdown.getAttribute('data-emailsync-sequence') || '0') + 1;
	dropdown.setAttribute('data-emailsync-sequence',String(sequence));
	emailsync__connection_state(dropdown,'warning',emailsync__language('_emailsync_connection_checking'));
	let post = new FormData();
	post.append('settings',true);
	post.append('type','general-emailsync');
	post.append('action','test_connection');
	post.append('id',dropdown.getAttribute('data-emailsync-job') || 'new');
	post.append('side',side);
	['host','port','security','username','password'].forEach(field => post.append(side+'_'+field,values[field]));
	fiCMS__refresh(false,post,false,{params:['loadwidget=settings','settingsType=general-emailsync']}).then(response => {
		if (parseInt(dropdown.getAttribute('data-emailsync-sequence') || '0') !== sequence) return;
		let data = fiCMS__json(response), result = data && data.result ? data.result : false;
		if (result && result.result === true) {
			let message = emailsync__language('_emailsync_connection_ready').replace('%count%',String(parseInt(result.folders || 0)));
			emailsync__connection_state(dropdown,'success',message);
			return;
		}
		let error = result && result.error ? String(result.error) : 'imap_connection_failed';
		emailsync__connection_state(dropdown,'error',emailsync__language('_emailsync_connection_failed').replace('%error%',error));
	}).catch(() => emailsync__connection_state(dropdown,'error',emailsync__language('_emailsync_connection_failed').replace('%error%','request_failed')));
}

function emailsync__connection_schedule(dropdown) {
	if (!dropdown) return false;
	if (dropdown._emailsyncTimer) clearTimeout(dropdown._emailsyncTimer);
	emailsync__connection_state(dropdown,'warning',emailsync__language('_emailsync_connection_pending'));
	dropdown._emailsyncTimer = setTimeout(emailsync__connection_test,600,dropdown);
	return true;
}

function emailsync__connection_bind(dropdown) {
	if (!dropdown || dropdown._emailsyncReady) return false;
	dropdown._emailsyncReady = true;
	dropdown.querySelectorAll('input,select').forEach(input => {
		input.addEventListener('input',() => {
			if (input.hasAttribute('data-emailsync-domain-source')) emailsync__domain_from_username(input);
			emailsync__connection_schedule(dropdown);
		});
		input.addEventListener('change',() => {
			if (input.hasAttribute('data-emailsync-domain-source')) emailsync__domain_from_username(input);
			emailsync__connection_schedule(dropdown);
		});
	});
	emailsync__connection_schedule(dropdown);
	return true;
}

if (typeof mutations__add === 'function') mutations__add('[data-emailsync-connection]',emailsync__connection_bind);
