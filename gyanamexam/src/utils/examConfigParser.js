/**
 * Exam Configuration Parser
 * Parses and validates exam configuration data
 * Requirements: 25.1, 25.2, 25.3
 */

/**
 * Parse and validate exam configuration data
 * @param {Object} data - Raw exam configuration data
 * @returns {Object} Parsed and validated exam configuration
 * @throws {Error} If validation fails with descriptive error message
 */
export function parseExamConfig(data) {
  if (!data || typeof data !== 'object') {
    throw new Error('Exam configuration must be an object');
  }

  // Validate required fields
  if (!data.examId || typeof data.examId !== 'string') {
    throw new Error('examId is required and must be a string');
  }

  if (!data.examType || typeof data.examType !== 'string') {
    throw new Error('examType is required and must be a string');
  }

  if (!['demo', 'main'].includes(data.examType)) {
    throw new Error('examType must be either "demo" or "main"');
  }

  if (typeof data.duration !== 'number' || data.duration <= 0) {
    throw new Error('duration is required and must be a positive number');
  }

  if (typeof data.questionCount !== 'number' || data.questionCount <= 0) {
    throw new Error('questionCount is required and must be a positive number');
  }

  if (!data.security || typeof data.security !== 'object') {
    throw new Error('security configuration is required and must be an object');
  }

  // Validate security configuration
  const security = data.security;
  if (typeof security.fullScreenRequired !== 'boolean') {
    throw new Error('security.fullScreenRequired must be a boolean');
  }

  if (typeof security.cameraRequired !== 'boolean') {
    throw new Error('security.cameraRequired must be a boolean');
  }

  if (typeof security.microphoneRequired !== 'boolean') {
    throw new Error('security.microphoneRequired must be a boolean');
  }

  // Build validated configuration object
  const config = {
    examId: data.examId,
    examType: data.examType,
    duration: data.duration,
    questionCount: data.questionCount,
    security: {
      fullScreenRequired: security.fullScreenRequired,
      cameraRequired: security.cameraRequired,
      microphoneRequired: security.microphoneRequired
    }
  };

  // Include optional fields if present
  if (data.title !== undefined) {
    config.title = String(data.title);
  }

  if (data.subject !== undefined) {
    config.subject = String(data.subject);
  }

  if (data.totalQuestions !== undefined) {
    if (typeof data.totalQuestions !== 'number' || data.totalQuestions <= 0) {
      throw new Error('totalQuestions must be a positive number');
    }
    config.totalQuestions = data.totalQuestions;
  }

  if (data.questionBankSize !== undefined) {
    if (typeof data.questionBankSize !== 'number' || data.questionBankSize <= 0) {
      throw new Error('questionBankSize must be a positive number');
    }
    config.questionBankSize = data.questionBankSize;
  }

  if (data.randomSeed !== undefined) {
    if (typeof data.randomSeed !== 'number') {
      throw new Error('randomSeed must be a number');
    }
    config.randomSeed = data.randomSeed;
  }

  if (data.instructions !== undefined) {
    config.instructions = String(data.instructions);
  }

  if (data.passingScore !== undefined) {
    if (typeof data.passingScore !== 'number' || data.passingScore < 0 || data.passingScore > 100) {
      throw new Error('passingScore must be a number between 0 and 100');
    }
    config.passingScore = data.passingScore;
  }

  if (data.createdAt !== undefined) {
    config.createdAt = String(data.createdAt);
  }

  if (data.scheduledAt !== undefined) {
    config.scheduledAt = String(data.scheduledAt);
  }

  // Include optional security fields
  if (security.tabSwitchLimit !== undefined) {
    if (typeof security.tabSwitchLimit !== 'number' || security.tabSwitchLimit < 0) {
      throw new Error('security.tabSwitchLimit must be a non-negative number');
    }
    config.security.tabSwitchLimit = security.tabSwitchLimit;
  }

  if (security.allowReview !== undefined) {
    if (typeof security.allowReview !== 'boolean') {
      throw new Error('security.allowReview must be a boolean');
    }
    config.security.allowReview = security.allowReview;
  }

  if (security.allowSkip !== undefined) {
    if (typeof security.allowSkip !== 'boolean') {
      throw new Error('security.allowSkip must be a boolean');
    }
    config.security.allowSkip = security.allowSkip;
  }

  return config;
}

/**
 * Format exam configuration object for serialization
 * @param {Object} config - Exam configuration object
 * @returns {Object} Formatted configuration data
 * @throws {Error} If config is invalid
 */
export function formatExamConfig(config) {
  if (!config || typeof config !== 'object') {
    throw new Error('Configuration must be an object');
  }

  // Validate required fields exist
  if (!config.examId) {
    throw new Error('examId is required');
  }

  if (!config.examType) {
    throw new Error('examType is required');
  }

  if (!config.duration) {
    throw new Error('duration is required');
  }

  if (!config.questionCount) {
    throw new Error('questionCount is required');
  }

  if (!config.security) {
    throw new Error('security configuration is required');
  }

  // Build formatted data object
  const data = {
    examId: config.examId,
    examType: config.examType,
    duration: config.duration,
    questionCount: config.questionCount,
    security: {
      fullScreenRequired: config.security.fullScreenRequired,
      cameraRequired: config.security.cameraRequired,
      microphoneRequired: config.security.microphoneRequired
    }
  };

  // Include optional fields if present
  if (config.title !== undefined) {
    data.title = config.title;
  }

  if (config.subject !== undefined) {
    data.subject = config.subject;
  }

  if (config.totalQuestions !== undefined) {
    data.totalQuestions = config.totalQuestions;
  }

  if (config.questionBankSize !== undefined) {
    data.questionBankSize = config.questionBankSize;
  }

  if (config.randomSeed !== undefined) {
    data.randomSeed = config.randomSeed;
  }

  if (config.instructions !== undefined) {
    data.instructions = config.instructions;
  }

  if (config.passingScore !== undefined) {
    data.passingScore = config.passingScore;
  }

  if (config.createdAt !== undefined) {
    data.createdAt = config.createdAt;
  }

  if (config.scheduledAt !== undefined) {
    data.scheduledAt = config.scheduledAt;
  }

  // Include optional security fields
  if (config.security.tabSwitchLimit !== undefined) {
    data.security.tabSwitchLimit = config.security.tabSwitchLimit;
  }

  if (config.security.allowReview !== undefined) {
    data.security.allowReview = config.security.allowReview;
  }

  if (config.security.allowSkip !== undefined) {
    data.security.allowSkip = config.security.allowSkip;
  }

  return data;
}
