import gsap from 'gsap';

export function initVelocityMarquee(): gsap.Context {
  // Pure GPU CSS keyframe animation is handled by .marquee-css-track in global.css (100% compositor thread)
  // Avoiding JS transform ticker conflict on the same element prevents jitter and frame drops
  return gsap.context(() => {});
}
