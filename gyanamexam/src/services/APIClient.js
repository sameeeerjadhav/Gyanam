import { CONFIG } from '../config.js';

const BASE_URL = CONFIG.API_BASE_URL;

class ApiClient {
  // 'admin' or 'student' — set once on portal load
  static _scope = 'student';

  static setScope(scope) {
    this._scope = scope; // 'admin' or 'student'
  }

  static _tokenKey() {
    return this._scope === 'admin' ? 'gyanam_admin_token' : 'gyanam_student_token';
  }

  static _userKey() {
    return this._scope === 'admin' ? 'gyanam_admin_user' : 'gyanam_student_user';
  }

  static getToken() {
    return localStorage.getItem(this._tokenKey());
  }

  static setToken(token) {
    localStorage.setItem(this._tokenKey(), token);
  }

  static removeToken() {
    localStorage.removeItem(this._tokenKey());
    localStorage.removeItem(this._userKey());
  }

  static async getDashboardStats() {
    return this.request('/dashboard/stats');
  }

  static async request(endpoint, options = {}) {
    const token = this.getToken();
    const headers = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      ...options.headers
    };

    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    const config = {
      ...options,
      headers
    };

    try {
      const response = await fetch(`${BASE_URL}${endpoint}`, config);

      if (response.status === 401) {
        this.removeToken();
        // Dispatch a SPA-friendly auth event instead of a hard reload
        window.dispatchEvent(new CustomEvent('gyanam:unauthorized'));
        const err = new Error('Unauthorized');
        err.status = 401;
        throw err;
      }

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || 'API Request Failed');
      }

      return data;
    } catch (error) {
      console.error(`API Error [${endpoint}]:`, error);
      throw error;
    }
  }

  static get(endpoint) {
    return this.request(endpoint, { method: 'GET' });
  }

  static post(endpoint, body) {
    return this.request(endpoint, {
      method: 'POST',
      body: JSON.stringify(body)
    });
  }

  static put(endpoint, body) {
    return this.request(endpoint, {
      method: 'PUT',
      body: JSON.stringify(body)
    });
  }

  static patch(endpoint, body) {
    return this.request(endpoint, {
      method: 'PATCH',
      body: JSON.stringify(body)
    });
  }

  static delete(endpoint) {
    return this.request(endpoint, { method: 'DELETE' });
  }

  // ─── Auth ───────────────────────────────────
  static async login(username, password) {
    const data = await this.post('/auth/login', { username, password });
    this.setToken(data.token);
    localStorage.setItem(this._userKey(), JSON.stringify(data.user));
    return data;
  }

  static async studentLogin(payload) {
    const data = await this.post('/student/login', payload);
    this.setToken(data.token);
    localStorage.setItem(this._userKey(), JSON.stringify({ ...data.user, role: 'student' }));
    return data;
  }

  static async logout() {
    try { await this.post('/auth/logout'); } catch (e) { }
    this.removeToken();
  }

  static getUser() {
    try { return JSON.parse(localStorage.getItem(this._userKey())); } catch (e) { return null; }
  }

  // ─── Question Banks ─────────────────────────
  static async getQuestionBanks() {
    return this.request('/question-banks');
  }

  static async getQuestionBankQuestions(id) {
    return this.request(`/question-banks/${id}/questions`);
  }

  static async createQuestionBank(data) { return this.post('/question-banks', data); }
  static updateQuestionBank(id, data) { return this.put(`/question-banks/${id}`, data); }
  static deleteQuestionBank(id) { return this.delete(`/question-banks/${id}`); }
  static assignQuestionBank(id, centres) { return this.post(`/question-banks/${id}/assign`, { centres }); }

  // ─── Questions ─────────────────────────────
  static addQuestion(bankId, data) { return this.post(`/question-banks/${bankId}/questions`, data); }
  static updateQuestion(bankId, qId, data) { return this.put(`/question-banks/${bankId}/questions/${qId}`, data); }
  static deleteQuestion(bankId, qId) { return this.delete(`/question-banks/${bankId}/questions/${qId}`); }
  static importQuestions(bankId, csv) { return this.post(`/question-banks/${bankId}/import-questions`, { csv }); }

  // ─── Exam Configs ──────────────────────────
  static getExams() { return this.get('/exam-configs'); }
  static createExam(data) { return this.post('/exam-configs', data); }
  static updateExam(id, data) { return this.put(`/exam-configs/${id}`, data); }
  static deleteExam(id) { return this.delete(`/exam-configs/${id}`); }
  static toggleExam(id) { return this.patch(`/exam-configs/${id}/toggle-active`); }

  // ─── Students ──────────────────────────────
  static getStudents() { return this.get('/students'); }
  static createStudent(data) { return this.post('/students', data); }
  static updateStudent(id, data) { return this.put(`/students/${id}`, data); }
  static deleteStudent(id) { return this.delete(`/students/${id}`); }
  static importStudents(csv) { return this.post('/students/import', { csv }); }
  static getStudentHistory(id) { return this.get(`/students/${id}/history`); }
  static bulkUpdateStudents(studentIds, fields) { return this.post('/students/bulk-update', { student_ids: studentIds, ...fields }); }
  static getStudentExams() { return this.get('/student/exams'); }

  // ─── Results ────────────────────────────────
  static getResults(params = {}) {
    let query = '';
    if (params.since) query = `?since=${params.since}`;
    return this.request('/results' + query);
  }
  static async exportResults() {
    const response = await fetch(`${BASE_URL}/results/export`, {
      headers: { 'Authorization': `Bearer ${this.getToken()}` }
    });
    return response.text();
  }

  // ─── Live Monitoring ───────────────────────
  static getLiveSessions() { return this.get('/live/active'); }
  static extendTime(studentId, examId, extra_minutes) {
    return this.post(`/live/${studentId}/exams/${examId}/extend`, { extra_minutes });
  }

  // ─── Student Exam flow ─────────────────────
  static getExamQuestions(id) { return this.get(`/student/exam/${id}/questions`); }
  static pulseHeartbeat(id) { return this.post(`/student/exam/${id}/heartbeat`); }
  static submitExam(id, payload) { return this.post(`/student/exam/${id}/submit`, payload); }
  static getSubmissionResult(subId) { return this.get(`/student/result/${subId}`); }
  // Student's own history (uses student-scoped route, not admin route)
  static getMyHistory() { return this.get('/student/history'); }

  // ─── Exam Assignments ──────────────────────
  static getAssignedStudents() { return this.get('/assignments/students'); }
  static getAssignableExams() { return this.get('/assignments/exams'); }
  static assignExam(studentId, examId, maxAttempts) {
    return this.post('/assignments/assign', { student_id: studentId, exam_id: examId, max_attempts: maxAttempts });
  }
  static bulkAssignExam(studentIds, examId, maxAttempts) {
    return this.post('/assignments/bulk-assign', { student_ids: studentIds, exam_id: examId, max_attempts: maxAttempts });
  }
  static updateAttempts(studentId, examId, maxAttempts) {
    return this.put(`/assignments/${studentId}/exams/${examId}/attempts`, { max_attempts: maxAttempts });
  }
  static unassignExam(studentId, examId) {
    return this.delete(`/assignments/${studentId}/exams/${examId}`);
  }

  // ─── Portal Data (Real ATC centres + Courses from main portal) ─────────────
  /** Returns { centres: [{code, name}] } — real ATC centres from users table */
  static getPortalCentres() { return this.get('/portal-centres'); }
  /** Returns { courses: [{id, course_name, course_type}] } — synced courses */
  static getPortalCourses() { return this.get('/portal-courses'); }
  /** Returns { centres: [{code, name, centre_type, district, state}], types: [...] } */
  static getPortalATCCentres() { return this.get('/portal-atc-centres'); }

  // ─── Portal User Credentials Management ────────────────────────────
  static getPortalUsers() { return this.get('/portal-users'); }
  static createPortalUser(data) { return this.post('/portal-users', data); }
  static updatePortalUser(data) { return this.post('/portal-users', data); }
  static deletePortalUser(username) { return this.delete(`/portal-users/${username}`); }

  // ─── Question Flags ────────────────────────
  static flagQuestion(submissionId, questionId, reason) {
    return this.post('/student/flags', { submission_id: submissionId, question_id: questionId, reason });
  }
  static getFlags(status = '') {
    return this.get('/flags' + (status ? `?status=${status}` : ''));
  }
  static updateFlag(id, status, adminNote = '') {
    return this.patch(`/flags/${id}`, { status, admin_note: adminNote });
  }
}

export { ApiClient as APIClient };
export default ApiClient;
