/**
 * Question Data Parser
 * Parses and validates question bank data
 * Requirements: 26.1, 26.2, 26.3
 */

/**
 * Parse and validate question data
 * @param {Object} data - Raw question data
 * @returns {Object} Parsed and validated question
 * @throws {Error} If validation fails with descriptive error message
 */
export function parseQuestion(data) {
  if (!data || typeof data !== 'object') {
    throw new Error('Question data must be an object');
  }

  // Validate required fields
  if (!data.id || typeof data.id !== 'string') {
    throw new Error('id is required and must be a string');
  }

  if (!data.type || typeof data.type !== 'string') {
    throw new Error('type is required and must be a string');
  }

  const validTypes = ['multiple-choice-single', 'multiple-choice-multiple', 'true-false'];
  if (!validTypes.includes(data.type)) {
    throw new Error(`type must be one of: ${validTypes.join(', ')}`);
  }

  if (!data.text || typeof data.text !== 'string') {
    throw new Error('text is required and must be a string');
  }

  if (!Array.isArray(data.options)) {
    throw new Error('options is required and must be an array');
  }

  if (data.options.length === 0) {
    throw new Error('options array must not be empty');
  }

  // Validate options structure
  for (let i = 0; i < data.options.length; i++) {
    const option = data.options[i];
    if (!option || typeof option !== 'object') {
      throw new Error(`options[${i}] must be an object`);
    }
    if (!option.id || typeof option.id !== 'string') {
      throw new Error(`options[${i}].id is required and must be a string`);
    }
    if (!option.text || typeof option.text !== 'string') {
      throw new Error(`options[${i}].text is required and must be a string`);
    }
    if (typeof option.order !== 'number') {
      throw new Error(`options[${i}].order is required and must be a number`);
    }
  }

  // Build validated question object
  const question = {
    id: data.id,
    type: data.type,
    text: data.text,
    options: data.options.map(opt => ({
      id: opt.id,
      text: opt.text,
      order: opt.order
    }))
  };

  // Include optional fields if present
  if (data.correctAnswer !== undefined) {
    question.correctAnswer = String(data.correctAnswer);
  }

  if (data.marks !== undefined) {
    if (typeof data.marks !== 'number' || data.marks < 0) {
      throw new Error('marks must be a non-negative number');
    }
    question.marks = data.marks;
  }

  if (data.negativeMarks !== undefined) {
    if (typeof data.negativeMarks !== 'number' || data.negativeMarks < 0) {
      throw new Error('negativeMarks must be a non-negative number');
    }
    question.negativeMarks = data.negativeMarks;
  }

  if (data.difficulty !== undefined) {
    const validDifficulties = ['easy', 'medium', 'hard'];
    if (!validDifficulties.includes(data.difficulty)) {
      throw new Error(`difficulty must be one of: ${validDifficulties.join(', ')}`);
    }
    question.difficulty = data.difficulty;
  }

  if (data.topic !== undefined) {
    question.topic = String(data.topic);
  }

  if (data.metadata !== undefined) {
    if (typeof data.metadata !== 'object' || data.metadata === null) {
      throw new Error('metadata must be an object');
    }
    question.metadata = { ...data.metadata };
  }

  return question;
}

/**
 * Format question object for serialization
 * @param {Object} question - Question object
 * @returns {Object} Formatted question data
 * @throws {Error} If question is invalid
 */
export function formatQuestion(question) {
  if (!question || typeof question !== 'object') {
    throw new Error('Question must be an object');
  }

  // Validate required fields exist
  if (!question.id) {
    throw new Error('id is required');
  }

  if (!question.type) {
    throw new Error('type is required');
  }

  if (!question.text) {
    throw new Error('text is required');
  }

  if (!Array.isArray(question.options)) {
    throw new Error('options is required and must be an array');
  }

  if (question.options.length === 0) {
    throw new Error('options array must not be empty');
  }

  // Build formatted data object
  const data = {
    id: question.id,
    type: question.type,
    text: question.text,
    options: question.options.map(opt => ({
      id: opt.id,
      text: opt.text,
      order: opt.order
    }))
  };

  // Include optional fields if present
  if (question.correctAnswer !== undefined) {
    data.correctAnswer = question.correctAnswer;
  }

  if (question.marks !== undefined) {
    data.marks = question.marks;
  }

  if (question.negativeMarks !== undefined) {
    data.negativeMarks = question.negativeMarks;
  }

  if (question.difficulty !== undefined) {
    data.difficulty = question.difficulty;
  }

  if (question.topic !== undefined) {
    data.topic = question.topic;
  }

  if (question.metadata !== undefined) {
    data.metadata = { ...question.metadata };
  }

  return data;
}
