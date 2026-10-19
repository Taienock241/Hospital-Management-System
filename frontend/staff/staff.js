/**
 * Hopewell Hospital Staff Portal demo utilities.
 * Client-side checks are not a security boundary; production PHP/MySQL
 * authentication and authorization must be enforced on the server.
 */

const DEMO_DATA = {
  mq_patients: [
    { id: 'P101', name: 'John Kiprono', phone: '0712345678', gender: 'Male', dob: '1988-04-12', blood: 'O+' },
    { id: 'P102', name: 'Faith Wanjiku', phone: '0722987654', gender: 'Female', dob: '1995-09-23', blood: 'A+' },
    { id: 'P103', name: 'Amina Mohamed', phone: '0733112233', gender: 'Female', dob: '2001-01-15', blood: 'B+' },
    { id: 'P104', name: 'Brian Omondi', phone: '0744556677', gender: 'Male', dob: '1979-11-30', blood: 'AB-' }
  ],
  mq_appointments: [
    { id: 'APT-001', patient: 'John Kiprono', doctor: 'Dr. Sarah Ndwiga', dept: 'Cardiology', date: '2026-10-18', time: '09:30', status: 'Confirmed' },
    { id: 'APT-002', patient: 'Faith Wanjiku', doctor: 'Dr. Kevin Mutua', dept: 'Pediatrics', date: '2026-10-18', time: '10:15', status: 'Pending' },
    { id: 'APT-003', patient: 'Brian Omondi', doctor: 'Dr. Jane Mwangi', dept: 'General Medicine', date: '2026-10-18', time: '11:00', status: 'Completed' }
  ],
  mq_queue: [
    { ticket: 'A01', patient: 'Faith Wanjiku', dept: 'Pediatrics', arrived: '08:45 AM', arrivedAt: '2026-10-18T08:45:00', status: 'Waiting' },
    { ticket: 'A02', patient: 'Amina Mohamed', dept: 'Cardiology', arrived: '09:05 AM', arrivedAt: '2026-10-18T09:05:00', status: 'Called' },
    { ticket: 'A03', patient: 'John Kiprono', dept: 'General Medicine', arrived: '09:20 AM', arrivedAt: '2026-10-18T09:20:00', status: 'Waiting' }
  ],
  mq_doctors: [
    { id: 'D01', name: 'Dr. Sarah Ndwiga', dept: 'Cardiology', specialty: 'Cardiologist', available: 'On Duty' },
    { id: 'D02', name: 'Dr. Kevin Mutua', dept: 'Pediatrics', specialty: 'Pediatric Specialist', available: 'On Duty' },
    { id: 'D03', name: 'Dr. Jane Mwangi', dept: 'General Medicine', specialty: 'General Practitioner', available: 'Off Duty' }
  ],
  mq_depts: [
    { id: 'DEP-CARD', code: 'CARD', name: 'Cardiology', lead: 'Dr. Sarah Ndwiga', rooms: 3, status: 'Active' },
    { id: 'DEP-PED', code: 'PED', name: 'Pediatrics', lead: 'Dr. Kevin Mutua', rooms: 4, status: 'Active' },
    { id: 'DEP-GEN', code: 'GEN', name: 'General Medicine', lead: 'Dr. Jane Mwangi', rooms: 6, status: 'Active' }
  ]
};

function storageRead(storage, key, fallback) {
  try {
    const raw = storage.getItem(key);
    if (!raw) return fallback;
    const value = JSON.parse(raw);
    return value === null || value === undefined ? fallback : value;
  } catch (error) {
    console.warn(`Unable to read ${key} from browser storage.`, error);
    return fallback;
  }
}

function storageWrite(storage, key, value) {
  try {
    storage.setItem(key, JSON.stringify(value));
    return true;
  } catch (error) {
    showToast('Unable to save changes in this browser.', 'error');
    console.error(`Unable to write ${key} to browser storage.`, error);
    return false;
  }
}

function makeId(prefix, records, field = 'id') {
  const used = new Set(records.map(record => record[field]).filter(Boolean));
  let number = records.length + 1;
  let id = `${prefix}${String(number).padStart(3, '0')}`;
  while (used.has(id)) {
    number += 1;
    id = `${prefix}${String(number).padStart(3, '0')}`;
  }
  return id;
}

const DataStore = {
  get(key) {
    const value = storageRead(localStorage, key, []);
    return Array.isArray(value) ? value : [];
  },
  set(key, data) {
    return storageWrite(localStorage, key, Array.isArray(data) ? data : []);
  }
};

(function initStorage() {
  Object.entries(DEMO_DATA).forEach(([key, value]) => {
    if (!localStorage.getItem(key)) storageWrite(localStorage, key, value);
  });
})();

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[character]));
}

function todayIso() {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

function showToast(message, type = 'success') {
  const existing = document.getElementById('mq_toast');
  if (existing) existing.remove();
  const toast = document.createElement('div');
  toast.id = 'mq_toast';
  toast.textContent = message;
  toast.className = `toast toast-${type}`;
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 2800);
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

const ROLE_PERMISSIONS = {
  admin: ['dashboard.html', 'patients.html', 'appointments.html', 'queue.html', 'doctors.html', 'departments.html', 'reports.html'],
  doctor: ['dashboard.html', 'appointments.html', 'queue.html', 'patients.html'],
  nurse: ['dashboard.html', 'queue.html', 'patients.html'],
  receptionist: ['dashboard.html', 'patients.html', 'appointments.html', 'queue.html']
};

function getCurrentUser() {
  const user = storageRead(localStorage, 'mq_current_user', null);
  if (!user || typeof user !== 'object' || !user.name || !user.role || !ROLE_PERMISSIONS[user.role.toLowerCase()]) return null;
  return user;
}

function setCurrentUser(user) {
  if (user && user.name && user.role && ROLE_PERMISSIONS[user.role.toLowerCase()]) {
    storageWrite(localStorage, 'mq_current_user', user);
  }
}

function clearAuth() {
  ['mq_current_user', 'hw_current_user'].forEach(key => {
    try {
      localStorage.removeItem(key);
      sessionStorage.removeItem(key);
    } catch (error) {
      console.warn(`Unable to clear ${key}.`, error);
    }
  });
}

function logoutStaff() {
  clearAuth();
  window.location.href = 'logout.html';
}

function checkPageAccess() {
  const currentPage = window.location.pathname.split('/').pop() || 'dashboard.html';
  if (currentPage === 'login.html' || currentPage === 'logout.html') return true;

  const user = getCurrentUser();
  if (!user) {
    window.location.replace('login.html');
    return false;
  }

  const allowed = ROLE_PERMISSIONS[user.role.toLowerCase()];
  if (!allowed.includes(currentPage)) {
    alert(`Access denied: your ${user.role} role cannot view this page.`);
    window.location.replace(`${allowed[0] || 'login.html'}`);
    return false;
  }

  const initials = user.name.split(/\s+/).map(part => part[0]).join('').substring(0, 2).toUpperCase();
  const avatar = document.querySelector('.user-avatar');
  const name = document.querySelector('.user-profile div strong');
  const dept = document.querySelector('.user-profile div small');
  if (avatar) avatar.textContent = initials;
  if (name) name.textContent = `${user.name} (${user.role.toUpperCase()})`;
  if (dept) dept.textContent = user.dept || 'Hopewell Hospital';

  document.querySelectorAll('.sidebar-menu li a').forEach(link => {
    if (link.getAttribute('href') !== 'logout.html' && !allowed.includes(link.getAttribute('href'))) {
      link.parentElement.hidden = true;
    }
  });
  return true;
}

document.addEventListener('DOMContentLoaded', () => {
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

  window.addEventListener('pageshow', event => {
    if (event.persisted) checkPageAccess();
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      document.querySelectorAll('.modal-backdrop.active').forEach(modal => closeModal(modal.id));
    }
  });
});
