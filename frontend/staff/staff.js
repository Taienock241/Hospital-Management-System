/** Shared Hopewell staff portal helpers. Data and authorization come from the API. */

const API_BASE = '/api';
let currentUser = null;
let csrfToken = null;

const ROLE_PERMISSIONS = {
  admin: ['dashboard.html', 'patients.html', 'appointments.html', 'queue.html', 'doctors.html', 'departments.html', 'reports.html', 'staff-management.html'],
  doctor: ['dashboard.html', 'appointments.html', 'queue.html', 'patients.html'],
  nurse: ['dashboard.html', 'queue.html', 'patients.html'],
  receptionist: ['dashboard.html', 'patients.html', 'appointments.html', 'queue.html']
};

async function apiRequest(path, options = {}) {
  const method = (options.method || 'GET').toUpperCase();
  const headers = { 'Accept': 'application/json', ...(options.headers || {}) };
  if (options.body !== undefined) headers['Content-Type'] = 'application/json';
  if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && csrfToken) headers['X-CSRF-Token'] = csrfToken;
  const response = await fetch(`${API_BASE}${path}`, {
    ...options,
    method,
    headers,
    credentials: 'same-origin',
    body: options.body === undefined ? undefined : JSON.stringify(options.body)
  });
  const result = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(result.error || `Request failed (${response.status}).`);
    error.status = response.status;
    throw error;
  }
  if (result.csrfToken) csrfToken = result.csrfToken;
  return result;
}

function getCurrentUser() { return currentUser; }

function showToast(message, type = 'success') {
  const existing = document.getElementById('mq_toast');
  if (existing) existing.remove();
  const toast = document.createElement('div');
  toast.id = 'mq_toast';
  toast.textContent = message;
  toast.className = `toast toast-${type}`;
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 3500);
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[character]));
}

function todayIso() {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

function openModal(id) {
  const element = document.getElementById(id);
  if (element) {
    element.classList.add('active');
    const firstInput = element.querySelector('input, select, textarea, button');
    if (firstInput) firstInput.focus();
  }
}

function closeModal(id) {
  const element = document.getElementById(id);
  if (element) element.classList.remove('active');
}

async function checkPageAccess() {
  const currentPage = window.location.pathname.split('/').pop() || 'dashboard.html';
  if (currentPage === 'login.html' || currentPage === 'logout.html') return true;
  try {
    const auth = await apiRequest('/auth/me');
    currentUser = auth.user;
    csrfToken = auth.csrfToken;
  } catch (error) {
    window.location.replace('login.html');
    return false;
  }

  const allowed = ROLE_PERMISSIONS[currentUser.role] || [];
  if (!allowed.includes(currentPage)) {
    showToast('Your account does not have access to this page.', 'error');
    window.location.replace(`${allowed[0] || 'login.html'}`);
    return false;
  }

  const initials = (currentUser.name || 'Hopewell').split(/\s+/).map(part => part[0]).join('').substring(0, 2).toUpperCase();
  const avatar = document.querySelector('.user-avatar');
  const name = document.querySelector('.user-profile div strong');
  const dept = document.querySelector('.user-profile div small');
  if (avatar) avatar.textContent = initials;
  if (name) name.textContent = `${currentUser.name} (${currentUser.role.toUpperCase()})`;
  if (dept) dept.textContent = currentUser.department || 'Hopewell Hospital';

  document.querySelectorAll('.sidebar-menu li a').forEach(link => {
    if (link.getAttribute('href') !== 'logout.html' && !allowed.includes(link.getAttribute('href'))) {
      link.parentElement.hidden = true;
    }
  });
  if (currentUser.role === 'admin') {
    const menu = document.querySelector('.sidebar-menu');
    if (menu && !menu.querySelector('a[href="staff-management.html"]')) {
      const item = document.createElement('li');
      item.innerHTML = '<a href="staff-management.html">👥 Staff Accounts</a>';
      const logout = menu.querySelector('a[href="logout.html"]')?.parentElement;
      menu.insertBefore(item, logout || null);
    }
  }
  return true;
}

async function logoutStaff() {
  try { await apiRequest('/auth/logout', { method: 'POST', body: {} }); }
  catch (error) { console.warn('Sign-out request did not complete.', error); }
  window.location.href = 'logout.html';
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('a[href="logout.html"]').forEach(link => {
    link.addEventListener('click', event => {
      event.preventDefault();
      logoutStaff();
    });
  });
  const toggle = document.querySelector('.mobile-menu-btn');
  const sidebar = document.querySelector('.sidebar');
  if (toggle && sidebar) {
    toggle.setAttribute('aria-expanded', 'false');
    toggle.addEventListener('click', () => {
      const open = sidebar.classList.toggle('open');
      toggle.setAttribute('aria-expanded', String(open));
    });
  }
  document.querySelectorAll('.modal-backdrop').forEach(modal => {
    modal.addEventListener('click', event => {
      if (event.target === modal) closeModal(modal.id);
    });
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') document.querySelectorAll('.modal-backdrop.active').forEach(modal => closeModal(modal.id));
  });
});
