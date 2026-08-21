/**
 * Mock Student Data
 * 
 * Provides sample student credentials, exam history, and certificates
 * for testing and development purposes.
 * 
 * Requirements: 2.1, 12.1, 13.1
 */

/**
 * Sample student credentials for testing
 */
export const mockStudentCredentials = [
  {
    identifier: 'STUDENT001',
    centerName: 'Center A',
    examSlot: 'SLOT1',
    timeWindow: 'MORNING',
    studentData: {
      id: 'student_STUDENT001',
      name: 'Rajesh Kumar',
      email: 'rajesh.kumar@example.com',
      examAccess: ['exam_demo_001', 'exam_main_001']
    }
  },
  {
    identifier: 'STUDENT002',
    centerName: 'Center B',
    examSlot: 'SLOT2',
    timeWindow: 'AFTERNOON',
    studentData: {
      id: 'student_STUDENT002',
      name: 'Priya Sharma',
      email: 'priya.sharma@example.com',
      examAccess: ['exam_demo_001', 'exam_main_001']
    }
  },
  {
    identifier: 'STUDENT003',
    centerName: 'Center A',
    examSlot: 'SLOT1',
    timeWindow: 'MORNING',
    studentData: {
      id: 'student_STUDENT003',
      name: 'Amit Patel',
      email: 'amit.patel@example.com',
      examAccess: ['exam_demo_001']
    }
  },
  {
    identifier: 'TEST123',
    centerName: 'Test Center',
    examSlot: 'SLOT1',
    timeWindow: 'MORNING',
    studentData: {
      id: 'student_TEST123',
      name: 'Test Student',
      email: 'test@example.com',
      examAccess: ['exam_demo_001', 'exam_main_001']
    }
  }
];

/**
 * Sample exam history data
 */
export const mockExamHistory = {
  'student_STUDENT001': [
    {
      examId: 'exam_demo_001',
      examTitle: 'Demo Examination - Mathematics',
      examType: 'demo',
      status: 'completed',
      scheduledAt: '2024-01-10T09:00:00Z',
      attemptedAt: '2024-01-10T09:05:00Z',
      submittedAt: '2024-01-10T09:35:00Z',
      score: 85,
      totalQuestions: 20,
      attemptedQuestions: 20,
      correctAnswers: 17,
      marks: 17,
      totalMarks: 20,
      result: 'pass',
      certificateEligible: true
    },
    {
      examId: 'exam_main_001',
      examTitle: 'Final Examination 2024 - Mathematics',
      examType: 'main',
      status: 'not-started',
      scheduledAt: '2024-01-20T10:00:00Z',
      attemptedAt: null,
      submittedAt: null,
      score: null,
      totalQuestions: 40,
      attemptedQuestions: null,
      correctAnswers: null,
      marks: null,
      totalMarks: 40,
      result: null,
      certificateEligible: false
    }
  ],
  'student_STUDENT002': [
    {
      examId: 'exam_demo_001',
      examTitle: 'Demo Examination - Mathematics',
      examType: 'demo',
      status: 'completed',
      scheduledAt: '2024-01-10T09:00:00Z',
      attemptedAt: '2024-01-10T14:05:00Z',
      submittedAt: '2024-01-10T14:32:00Z',
      score: 75,
      totalQuestions: 20,
      attemptedQuestions: 20,
      correctAnswers: 15,
      marks: 15,
      totalMarks: 20,
      result: 'pass',
      certificateEligible: true
    },
    {
      examId: 'exam_main_001',
      examTitle: 'Final Examination 2024 - Mathematics',
      examType: 'main',
      status: 'completed',
      scheduledAt: '2024-01-15T10:00:00Z',
      attemptedAt: '2024-01-15T10:02:00Z',
      submittedAt: '2024-01-15T11:00:00Z',
      score: 92,
      totalQuestions: 40,
      attemptedQuestions: 40,
      correctAnswers: 37,
      marks: 37,
      totalMarks: 40,
      result: 'pass',
      certificateEligible: true
    }
  ],
  'student_STUDENT003': [
    {
      examId: 'exam_demo_001',
      examTitle: 'Demo Examination - Mathematics',
      examType: 'demo',
      status: 'in-progress',
      scheduledAt: '2024-01-10T09:00:00Z',
      attemptedAt: '2024-01-10T09:10:00Z',
      submittedAt: null,
      score: null,
      totalQuestions: 20,
      attemptedQuestions: 12,
      correctAnswers: null,
      marks: null,
      totalMarks: 20,
      result: null,
      certificateEligible: false
    }
  ],
  'student_TEST123': [
    {
      examId: 'exam_demo_001',
      examTitle: 'Demo Examination - Mathematics',
      examType: 'demo',
      status: 'completed',
      scheduledAt: '2024-01-08T09:00:00Z',
      attemptedAt: '2024-01-08T09:05:00Z',
      submittedAt: '2024-01-08T09:28:00Z',
      score: 55,
      totalQuestions: 20,
      attemptedQuestions: 20,
      correctAnswers: 11,
      marks: 11,
      totalMarks: 20,
      result: 'fail',
      certificateEligible: false
    },
    {
      examId: 'exam_main_001',
      examTitle: 'Final Examination 2024 - Mathematics',
      examType: 'main',
      status: 'expired',
      scheduledAt: '2024-01-12T10:00:00Z',
      attemptedAt: null,
      submittedAt: null,
      score: null,
      totalQuestions: 40,
      attemptedQuestions: null,
      correctAnswers: null,
      marks: null,
      totalMarks: 40,
      result: null,
      certificateEligible: false
    }
  ]
};

/**
 * Sample certificate data
 */
export const mockCertificates = {
  'student_STUDENT001': [
    {
      certificateId: 'cert_001',
      studentId: 'student_STUDENT001',
      studentName: 'Rajesh Kumar',
      examId: 'exam_demo_001',
      examTitle: 'Demo Examination - Mathematics',
      score: 85,
      grade: 'A',
      issuedAt: '2024-01-11T00:00:00Z',
      status: 'issued',
      validUntil: '2026-01-11T00:00:00Z',
      verificationCode: 'CERT-2024-RK-12345',
      downloadUrl: '/api/certificates/cert_001/download',
      metadata: {
        issuer: 'National Examination Board',
        signatoryName: 'Dr. Sunita Verma',
        signatoryTitle: 'Director of Examinations'
      }
    }
  ],
  'student_STUDENT002': [
    {
      certificateId: 'cert_002',
      studentId: 'student_STUDENT002',
      studentName: 'Priya Sharma',
      examId: 'exam_demo_001',
      examTitle: 'Demo Examination - Mathematics',
      score: 75,
      grade: 'B',
      issuedAt: '2024-01-11T00:00:00Z',
      status: 'issued',
      validUntil: '2026-01-11T00:00:00Z',
      verificationCode: 'CERT-2024-PS-12346',
      downloadUrl: '/api/certificates/cert_002/download',
      metadata: {
        issuer: 'National Examination Board',
        signatoryName: 'Dr. Sunita Verma',
        signatoryTitle: 'Director of Examinations'
      }
    },
    {
      certificateId: 'cert_003',
      studentId: 'student_STUDENT002',
      studentName: 'Priya Sharma',
      examId: 'exam_main_001',
      examTitle: 'Final Examination 2024 - Mathematics',
      score: 92,
      grade: 'A',
      issuedAt: '2024-01-16T00:00:00Z',
      status: 'issued',
      validUntil: '2026-01-16T00:00:00Z',
      verificationCode: 'CERT-2024-PS-12347',
      downloadUrl: '/api/certificates/cert_003/download',
      metadata: {
        issuer: 'National Examination Board',
        signatoryName: 'Dr. Sunita Verma',
        signatoryTitle: 'Director of Examinations'
      }
    }
  ],
  'student_STUDENT003': [],
  'student_TEST123': []
};

/**
 * Get student credentials by identifier
 * @param {string} identifier - Student identifier
 * @returns {Object|null} Student credentials or null if not found
 */
export function getStudentByIdentifier(identifier) {
  return mockStudentCredentials.find(student => student.identifier === identifier) || null;
}

/**
 * Get exam history for a student
 * @param {string} studentId - Student ID
 * @returns {Array} Exam history array
 */
export function getExamHistoryByStudentId(studentId) {
  return mockExamHistory[studentId] || [];
}

/**
 * Get certificates for a student
 * @param {string} studentId - Student ID
 * @returns {Array} Certificates array
 */
export function getCertificatesByStudentId(studentId) {
  return mockCertificates[studentId] || [];
}

/**
 * Validate student credentials
 * @param {Object} credentials - Login credentials
 * @returns {boolean} True if valid, false otherwise
 */
export function validateCredentials(credentials) {
  const student = mockStudentCredentials.find(s => 
    s.identifier === credentials.identifier &&
    s.centerName === credentials.centerName &&
    s.examSlot === credentials.examSlot &&
    s.timeWindow === credentials.timeWindow
  );
  return !!student;
}
