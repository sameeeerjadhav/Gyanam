/**
 * CoursesModule.js — Course list with checkboxes to create demo or main exams.
 */
import modalService from '../services/ModalService.js';

function norm(value) {
  return String(value || '').trim().replace(/\s+/g, ' ').toLowerCase();
}

function esc(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/"/g, '&quot;');
}

export async function renderCourses(ApiClient) {
  const el = document.getElementById('page-content');
  if (!el) return;

  let courses = [];
  let banks = [];
  let exams = [];
  try {
    const [courseRes, bankRes, examRes] = await Promise.all([
      ApiClient.getPortalCourses({ fresh: true }),
      ApiClient.getQuestionBanks(),
      ApiClient.getExams(),
    ]);
    courses = courseRes?.courses || [];
    banks = bankRes || [];
    exams = examRes || [];
  } catch (e) {
    el.innerHTML = `<div class="card" style="text-align:center;padding:3rem;color:var(--danger)">Failed to load courses: ${esc(e.message)}</div>`;
    return;
  }

  const banksBySubject = new Map();
  banks.forEach((bank) => {
    const key = norm(bank.subject);
    if (!key || banksBySubject.has(key)) return;
    banksBySubject.set(key, bank);
  });

  const rows = [];
  const seen = new Set();
  courses.forEach((course) => {
    const name = String(course.course_name || '').trim();
    const key = norm(name);
    if (!key || seen.has(key)) return;
    seen.add(key);
    rows.push({
      name,
      type: String(course.course_type || '').trim(),
      bank: banksBySubject.get(key) || null,
    });
  });
  banks.forEach((bank) => {
    const name = String(bank.subject || '').trim();
    const key = norm(name);
    if (!key || seen.has(key)) return;
    seen.add(key);
    rows.push({ name, type: '', bank });
  });
  rows.sort((a, b) => a.name.localeCompare(b.name));

  function examFor(row, type) {
    if (row.bank) {
      const byBank = exams.find((exam) => String(exam.question_bank_id) === String(row.bank.id) && exam.exam_type === type);
      if (byBank) return byBank;
    }
    return exams.find((exam) => norm(exam.subject) === norm(row.name) && exam.exam_type === type) || null;
  }

  function statusBadge(exam) {
    if (!exam) return '<span class="badge badge-gray">Not created</span>';
    return '<span class="badge badge-green">Created</span>';
  }

  el.innerHTML = `
    <div class="page-header">
      <div>
        <h2>Courses</h2>
        <p id="course-count-label">${rows.length} course(s)</p>
      </div>
      <div style="display:flex;gap:0.5rem;flex-wrap:wrap;justify-content:flex-end">
        <button id="course-demo-btn" class="btn btn-outline" type="button" disabled>Create Demo exams for selected</button>
        <button id="course-main-btn" class="btn btn-primary" type="button" disabled>Create Main exams for selected</button>
      </div>
    </div>
    <div class="card" style="margin-bottom:1rem;padding:1rem">
      <input id="course-search" class="form-input" type="search" placeholder="Search courses…" autocomplete="off">
    </div>
    <div class="table-wrap card">
      <table>
        <thead>
          <tr>
            <th style="width:42px"><input id="course-select-all" type="checkbox" aria-label="Select all courses"></th>
            <th>Course</th>
            <th>Type</th>
            <th>Question bank</th>
            <th>Demo</th>
            <th>Main</th>
          </tr>
        </thead>
        <tbody id="course-table-body">
          ${rows.map((row) => {
            const bank = row.bank;
            const bankText = bank
              ? `${esc(bank.title || row.name)} (${bank.questions_count || 0} Qs)`
              : '<span style="color:var(--danger)">No question bank</span>';
            return `<tr data-course="${esc(row.name)}">
              <td><input class="course-check" type="checkbox" value="${esc(row.name)}" aria-label="Select ${esc(row.name)}"></td>
              <td style="font-weight:600">${esc(row.name)}</td>
              <td>${esc(row.type || '—')}</td>
              <td>${bankText}</td>
              <td>${statusBadge(examFor(row, 'demo'))}</td>
              <td>${statusBadge(examFor(row, 'main'))}</td>
            </tr>`;
          }).join('') || '<tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:2rem">No courses synced yet.</td></tr>'}
        </tbody>
      </table>
    </div>`;

  const searchEl = document.getElementById('course-search');
  const selectAll = document.getElementById('course-select-all');
  const demoBtn = document.getElementById('course-demo-btn');
  const mainBtn = document.getElementById('course-main-btn');

  function visibleChecks() {
    return Array.from(document.querySelectorAll('#course-table-body tr')).filter((tr) => tr.style.display !== 'none').map((tr) => tr.querySelector('.course-check')).filter(Boolean);
  }

  function selectedNames() {
    return visibleChecks().filter((box) => box.checked).map((box) => box.value);
  }

  function refreshButtons() {
    const count = selectedNames().length;
    const label = count ? ` (${count})` : '';
    if (demoBtn) {
      demoBtn.disabled = count === 0;
      demoBtn.textContent = `Create Demo exams for selected${label}`;
    }
    if (mainBtn) {
      mainBtn.disabled = count === 0;
      mainBtn.textContent = `Create Main exams for selected${label}`;
    }
    if (selectAll) {
      const boxes = visibleChecks();
      selectAll.checked = boxes.length > 0 && boxes.every((box) => box.checked);
      selectAll.indeterminate = boxes.some((box) => box.checked) && !selectAll.checked;
    }
  }

  searchEl?.addEventListener('input', () => {
    const q = searchEl.value.trim().toLowerCase();
    document.querySelectorAll('#course-table-body tr').forEach((tr) => {
      const name = String(tr.dataset.course || '').toLowerCase();
      tr.style.display = !q || name.includes(q) ? '' : 'none';
    });
    refreshButtons();
  });

  selectAll?.addEventListener('change', () => {
    visibleChecks().forEach((box) => { box.checked = selectAll.checked; });
    refreshButtons();
  });

  document.querySelectorAll('.course-check').forEach((box) => {
    box.addEventListener('change', refreshButtons);
  });

  async function createSelected(kind) {
    const names = selectedNames();
    if (!names.length) {
      modalService.toast('Select at least one course', 'error');
      return;
    }
    const isDemo = kind === 'demo';
    const label = isDemo ? 'Demo' : 'Main';
    const mode = isDemo ? 'Normal' : 'Proctored';
    const ok = await modalService.confirm(
      `Create a ${label} exam for ${names.length} selected course(s).<br><br>Title: course name ${label.toUpperCase()}<br>Duration: 1 hour<br>Questions to show: 40<br>Mode: ${mode}<br>Questions are randomized.<br><br>A course that already has a ${label} exam is skipped. You can edit the exam afterwards.`,
      { title: `Create ${label} exams`, confirmText: `Create ${label} exams`, type: 'warning' }
    );
    if (!ok) return;

    demoBtn.disabled = true;
    mainBtn.disabled = true;
    const activeBtn = isDemo ? demoBtn : mainBtn;
    activeBtn.textContent = 'Creating…';
    try {
      const result = await ApiClient.bulkCreateExams(kind, names);
      const created = result.created || [];
      const skipped = result.skipped || [];
      const failed = result.failed || [];
      const capped = created.filter((row) => row.capped).length;
      const parts = [`Created ${created.length} ${label} exam(s).`];
      if (skipped.length) parts.push(`Skipped ${skipped.length} that already had a ${label} exam.`);
      if (capped) parts.push(`${capped} show fewer than 40 questions because the bank is smaller.`);
      if (failed.length) {
        const why = failed.slice(0, 3).map((row) => row.bank).join(', ');
        parts.push(`${failed.length} not created${why ? ` (${why})` : ''}.`);
      }
      modalService.toast(parts.join(' '), failed.length && !created.length ? 'error' : 'success');
      renderCourses(ApiClient);
    } catch (e) {
      modalService.toast('Could not create exams: ' + e.message, 'error');
      refreshButtons();
    }
  }

  demoBtn?.addEventListener('click', () => createSelected('demo'));
  mainBtn?.addEventListener('click', () => createSelected('main'));
}
