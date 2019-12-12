// React switch component, used in React Final Form, written in TypeScript
import React, { HTMLProps } from 'react';
import './SwitchComponent.css';

interface Props extends HTMLProps<HTMLInputElement> {
  name?: string;
  label?: string;
  checked?: boolean;
  disabled?: boolean;
  required?: boolean;
  valid?: boolean;
  validationError?: string;
  submitError?: string;
  value?: string;
  variant?: 'small';
}

const SwitchComponent: React.FC<Props> = (props: Props) => {
  const {
    value,
    name,
    label,
    disabled,
    required,
    onChange,
    valid,
    validationError,
    submitError,
    variant,
  } = props;
  const handleChange = (event: React.FormEvent<HTMLInputElement>) => {
    if (onChange) onChange(event);
  };
  const htmlFor = `switch_${name}`;

  return (
    <>
      <label className={`b-switch${variant ? ` b-switch--${variant}` : ''}`} htmlFor={htmlFor}>
        <input
          id={htmlFor}
          className="b-switch__input"
          type="checkbox"
          disabled={disabled}
          required={required}
          onChange={handleChange}
          checked={!!value}
        />
        <span className="b-switch__slider" />
        <span
          className={[
            'b-switch__label',
            !value ? 'b-switch__label--muted' : '',
          ].join(' ')}
        >
          {label}
        </span>
      </label>
      {!valid ? validationError || submitError : ''}
    </>
  );
};

export default SwitchComponent;
