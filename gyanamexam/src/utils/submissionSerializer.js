/**
 * Answer Submission Serializer
 * Serializes exam state into submission payload for backend API
 * Requirements: 27.1, 27.2, 27.3, 27.4, 27.5, 27.6
 */

/**
 * Generate a unique submission ID
 * @returns {string} UUID-like submission ID
 * @private
 */
function generateSubmissionId() {
  return 'sub_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
}

/**
 * Get browser information
 * @returns {string} Browser name and version
 * @private
 */
function getBrowserInfo() {
  // Handle Node.js environment
  if (typeof navigator === 'undefined') {
    return 'Node.js';
  }
  
  const ua = navigator.userAgent;
  let browser = 'Unknown';
  
  if (ua.indexOf('Firefox') > -1) {
    const match = ua.match(/Firefox\/(\d+)/);
    browser = match ? `Firefox ${match[1]}` : 'Firefox';
  } else if (ua.indexOf('Chrome') > -1) {
    const match = ua.match(/Chrome\/(\d+)/);
    browser = match ? `Chrome ${match[1]}` : 'Chrome';
  } else if (ua.indexOf('Safari') > -1) {
    const match = ua.match(/Version\/(\d+)/);
    browser = match ? `Safari ${match[1]}` : 'Safari';
  } else if (ua.indexOf('Edge') > -1) {
    const match = ua.match(/Edge\/(\d+)/);
    browser = match ? `Edge ${match[1]}` : 'Edge';
  }
  
  return browser;
}

/**
 * Get operating system information
 * @returns {string} OS name
 * @private
 */
function getOSInfo() {
  // Handle Node.js environment
  if (typeof navigator === 'undefined') {
    return 'Unknown';
  }
  
  const ua = navigator.userAgent;
  
  if (ua.indexOf('Win') > -1) return 'Windows';
  if (ua.indexOf('Mac') > -1) return 'macOS';
  if (ua.indexOf('Linux') > -1) return 'Linux';
  if (ua.indexOf('Android') > -1) return 'Android';
  if (ua.indexOf('iOS') > -1) return 'iOS';
  
  return 'Unknown';
}

/**
 * Get screen resolution
 * @returns {string} Screen resolution (e.g., "1920x1080")
 * @private
 */
function getScreenResolution() {
  // Handle Node.js environment
  if (typeof window === 'undefined' || typeof window.screen === 'undefined') {
    return 'Unknown';
  }
  
  return `${window.screen.width}x${window.screen.height}`;
}

/**
 * Serialize exam state into submission payload for backend API
 * 
 * @param {Object} examState - Current exam state from StateManager
 * @returns {Object} Submission payload formatted for backend API
 * @throws {Error} If examState is invalid or missing required fields
 * 
 * @example
 * const submission = serializeSubmission(stateManager.getState());
 * await apiClient.submitExam(submission);
 */
export function serializeSubmission(examState) {
  // Validate examState
  if (!examState || typeof examState !== 'object') {
    throw new Error('Exam state must be an object');
  }

  // Validate required fields
  if (!examState.studentId || typeof examState.studentId !== 'string') {
    throw new Error('studentId is required and must be a string');
  }

  if (!examState.examId || typeof examState.examId !== 'string') {
    throw new Error('examId is required and must be a string');
  }

  if (!examState.startTime) {
    throw new Error('startTime is required');
  }

  if (!Array.isArray(examState.questions)) {
    throw new Error('questions must be an array');
  }

  // Generate submission timestamp
  const submitTime = new Date().toISOString();

  // Serialize answers from questions
  const answers = examState.questions.map(question => {
    const answer = {
      questionId: question.id,
      answer: question.answer !== undefined ? question.answer : null,
      markedForReview: question.markedForReview || false
    };

    // Include attemptedAt if available
    if (question.attemptedAt) {
      answer.attemptedAt = question.attemptedAt;
    }

    // Include timeSpent if available
    if (question.timeSpent !== undefined) {
      answer.timeSpent = question.timeSpent;
    }

    return answer;
  });

  // Serialize security data
  const security = {
    tabSwitchCount: 0,
    fullScreenExitCount: 0,
    events: []
  };

  if (examState.security) {
    if (typeof examState.security.tabSwitchCount === 'number') {
      security.tabSwitchCount = examState.security.tabSwitchCount;
    }
    
    if (typeof examState.security.fullScreenExitCount === 'number') {
      security.fullScreenExitCount = examState.security.fullScreenExitCount;
    }
    
    if (Array.isArray(examState.security.events)) {
      security.events = examState.security.events.map(event => ({
        type: event.type,
        timestamp: event.timestamp,
        questionIndex: event.questionIndex !== undefined ? event.questionIndex : null,
        metadata: event.metadata || {}
      }));
    }
  }

  // Build submission payload
  const submission = {
    submissionId: generateSubmissionId(),
    examId: examState.examId,
    studentId: examState.studentId,
    startTime: typeof examState.startTime === 'number' 
      ? new Date(examState.startTime).toISOString() 
      : examState.startTime,
    submitTime: submitTime,
    duration: examState.duration || 0,
    answers: answers,
    security: security,
    metadata: {
      browser: getBrowserInfo(),
      os: getOSInfo(),
      screenResolution: getScreenResolution()
    }
  };

  return submission;
}
