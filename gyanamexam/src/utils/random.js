/**
 * Seeded Random Number Generator
 * 
 * Implements a Linear Congruential Generator (LCG) for deterministic randomization.
 * This ensures that the same seed produces the same sequence of random numbers,
 * which is essential for backend compatibility and reproducible question selection.
 * 
 * Requirements: 5.3, 25.4
 */

/**
 * Creates a seeded random number generator using Linear Congruential Generator algorithm
 * 
 * The LCG formula: state = (a * state + c) mod m
 * - a (multiplier): 1664525
 * - c (increment): 1013904223
 * - m (modulus): 2^32 (4294967296)
 * 
 * These constants are from Numerical Recipes and provide good statistical properties.
 * 
 * @param {number} seed - The seed value for deterministic randomization
 * @returns {Function} A function that returns a random number in the range [0, 1)
 * 
 * @example
 * const random = createSeededRandom(12345);
 * console.log(random()); // 0.39...
 * console.log(random()); // 0.72...
 * 
 * // Same seed produces same sequence
 * const random2 = createSeededRandom(12345);
 * console.log(random2()); // 0.39... (same as first call above)
 */
export function createSeededRandom(seed) {
  // Initialize state with the seed value
  let state = seed;
  
  // Return the generator function
  return function() {
    // Apply LCG formula
    state = (state * 1664525 + 1013904223) % 4294967296;
    
    // Normalize to [0, 1) range
    return state / 4294967296;
  };
}

/**
 * Selects a random subset of questions from a question bank using deterministic randomization
 * 
 * This function uses the Fisher-Yates shuffle algorithm with a seeded random generator
 * to ensure that the same seed always produces the same selection and order of questions.
 * This is critical for backend verification and exam consistency.
 * 
 * @param {Array} questionBank - The full array of questions to select from
 * @param {number} count - The number of questions to select (e.g., 40 from 100)
 * @param {number} seed - The seed value for deterministic randomization
 * @returns {Array} A new array containing the selected questions in randomized order
 * 
 * @example
 * const questions = [q1, q2, q3, ..., q100];
 * const selected = selectRandomQuestions(questions, 40, 12345);
 * // Returns 40 questions in a deterministic random order
 * 
 * // Same seed produces same selection
 * const selected2 = selectRandomQuestions(questions, 40, 12345);
 * // selected2 will be identical to selected
 * 
 * Requirements: 5.1, 5.2, 5.4, 5.5
 */
export function selectRandomQuestions(questionBank, count, seed) {
  // Validate inputs
  if (!Array.isArray(questionBank)) {
    throw new TypeError('questionBank must be an array');
  }
  
  if (count > questionBank.length) {
    throw new RangeError(`Cannot select ${count} questions from a bank of ${questionBank.length} questions`);
  }
  
  if (count < 0) {
    throw new RangeError('count must be non-negative');
  }
  
  // Create a copy to avoid mutating the original array
  const shuffled = shuffleArray(questionBank, seed);
  
  // Return the first 'count' elements
  return shuffled.slice(0, count);
}

/**
 * Shuffles an array deterministically using the Fisher-Yates algorithm with a seeded random generator
 * 
 * The Fisher-Yates shuffle ensures uniform distribution of all possible permutations.
 * Combined with a seeded random generator, this provides deterministic shuffling
 * that can be reproduced on the backend for verification.
 * 
 * This function is used for:
 * - Randomizing question order in an exam
 * - Randomizing option order within questions
 * 
 * @param {Array} array - The array to shuffle
 * @param {number} seed - The seed value for deterministic randomization
 * @returns {Array} A new shuffled array (original array is not modified)
 * 
 * @example
 * const options = ['A', 'B', 'C', 'D'];
 * const shuffled = shuffleArray(options, 12345);
 * // Returns options in a deterministic random order, e.g., ['C', 'A', 'D', 'B']
 * 
 * // Same seed produces same shuffle
 * const shuffled2 = shuffleArray(options, 12345);
 * // shuffled2 will be identical to shuffled
 * 
 * Requirements: 5.4, 5.5
 */
export function shuffleArray(array, seed) {
  // Validate input
  if (!Array.isArray(array)) {
    throw new TypeError('array must be an array');
  }
  
  // Create a copy to avoid mutating the original array
  const result = [...array];
  
  // Create seeded random generator
  const random = createSeededRandom(seed);
  
  // Fisher-Yates shuffle algorithm
  // Start from the end and swap each element with a random element before it
  for (let i = result.length - 1; i > 0; i--) {
    // Generate random index from 0 to i (inclusive)
    const j = Math.floor(random() * (i + 1));
    
    // Swap elements at positions i and j
    [result[i], result[j]] = [result[j], result[i]];
  }
  
  return result;
}
