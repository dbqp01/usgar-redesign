import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const read = (path: string) => readFileSync(path, 'utf8');
const guest = read('src/components/booking/GuestStep.astro');
const contact = read('src/components/contact/ContactForm.astro');
const login = read('src/pages/login.astro');

describe('privacy notice at data collection points', () => {
  it('guest step shows a purpose notice and privacy link before the inputs', () => {
    const notice = guest.indexOf('id="guest-privacy-notice"');
    expect(notice).toBeGreaterThan(-1);
    expect(notice).toBeLessThan(guest.indexOf('id="guest-name"'));
    expect(guest).toMatch(/href=\{privacyUrl\}[^>]*>\{t\('footer\.privacy'\)\}/);
    expect(guest).toContain('manage your booking');
    expect(guest).toContain('gestionar tu reserva');
    for (const id of ['guest-name', 'guest-email', 'guest-phone']) {
      expect(guest).toMatch(new RegExp(`id="${id}"[\\s\\S]{0,400}aria-describedby="guest-privacy-notice"`));
    }
  });

  it('contact form explains the purpose and links the privacy policy before the inputs', () => {
    const notice = contact.indexOf('id="contact-privacy-notice"');
    expect(notice).toBeGreaterThan(-1);
    expect(notice).toBeLessThan(contact.indexOf('id="contact-name"'));
    expect(contact).toMatch(/href=\{privacyUrl\}[^>]*>\{t\("footer\.privacy"\)\}/);
    expect(contact).toContain('answer your request');
    expect(contact).toContain('responder a tu solicitud');
    for (const id of ['contact-name', 'contact-email', 'contact-message']) {
      expect(contact).toMatch(new RegExp(`id="${id}"[\\s\\S]{0,400}aria-describedby="contact-privacy-notice"`));
    }
  });

  it('login page discloses data use before the sign-in and register forms', () => {
    const notice = login.indexOf('id="auth-privacy-notice"');
    expect(notice).toBeGreaterThan(-1);
    expect(notice).toBeLessThan(login.indexOf('id="oauth-container"'));
    expect(notice).toBeLessThan(login.indexOf('id="login-form"'));
    expect(notice).toBeLessThan(login.indexOf('id="register-form"'));
    expect(login).toMatch(/href=\{privacyUrl\}[^>]*>\{t\('footer\.privacy'\)\}/);
    expect(login).toContain('create and secure your account');
    expect(login).toContain('crear y proteger tu cuenta');
    for (const id of ['login-email', 'reg-name', 'reg-email']) {
      expect(login).toMatch(new RegExp(`id="${id}"[\\s\\S]{0,400}aria-describedby="auth-privacy-notice"`));
    }
  });

  it('localizes the notices in all four site languages', () => {
    for (const source of [guest, contact, login]) {
      for (const locale of ['en', 'es', 'fr', 'pt']) {
        expect(source).toMatch(new RegExp(`${locale}: ['"]`));
      }
    }
    expect(guest).toContain('réservation');
    expect(contact).toContain('votre demande');
    expect(login).toContain('votre compte');
    expect(guest).toContain('sua reserva');
    expect(contact).toContain('sua solicitação');
    expect(login).toContain('sua conta');
  });
});

describe('booking terms acceptance', () => {
  it('requires an explicit, labelled acceptance linked to the booking terms', () => {
    expect(guest).toMatch(/id="booking-acceptance"[\s\S]{0,300}required/);
    expect(guest).toMatch(/<label for="booking-acceptance"[\s\S]{0,200}\{acceptanceLabel\[lang\]\}/);
    expect(guest).toContain('I accept the Terms of Service for this booking.');
    expect(guest).toContain('Acepto los Términos y Condiciones de esta reserva.');
    expect(guest).toMatch(/href=\{termsUrl\}/);
    expect(guest).toMatch(/href=\{privacyUrl\}/);
  });

  it('blocks the step transition until the terms are accepted', () => {
    const gate = guest.indexOf('bookingAcceptance');
    const check = guest.indexOf('.checked', gate);
    const transition = guest.indexOf('wizardStore.setState({ step: 3', gate);
    expect(gate).toBeGreaterThan(-1);
    expect(check).toBeGreaterThan(gate);
    expect(transition).toBeGreaterThan(check);
    expect(guest).toMatch(/accept the Terms of Service to continue/i);
    expect(guest).toContain('Acepta los Términos y Condiciones para continuar');
  });
});
