/**
 * ProctorCamerasSection — Admin / ATC view.
 * Live camera watching is disabled (shared-hosting load).
 * Live Monitor still shows how many students are appearing.
 */
export function renderProctorCamerasHTML(/* sessions */) {
  // Intentionally empty: no live camera grid / Watch buttons / signaling polls.
  return '';
}

export async function bindProctorCameras(/* ApiClient, root */) {
  // No-op — live watch disabled.
}

export default { renderProctorCamerasHTML, bindProctorCameras };
