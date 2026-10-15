import { useRef } from 'react';
import { Search, X } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { InputGroup, InputGroupAddon, InputGroupButton, InputGroupInput } from '@/components/ui/input-group';

/** A search box with a clear (×) button while it has text; clearing keeps the focus in the box. */
export function SearchInput({
  value,
  onChange,
  placeholder,
  className,
  inputClassName,
}: {
  value: string;
  onChange: (value: string) => void;
  /** Also its accessible name. */
  placeholder: string;
  className?: string;
  inputClassName?: string;
}) {
  const input = useRef<HTMLInputElement>(null);
  return (
    <InputGroup className={className}>
      <InputGroupAddon>
        <Search />
      </InputGroupAddon>
      <InputGroupInput
        ref={input}
        value={value}
        className={inputClassName}
        placeholder={placeholder}
        aria-label={placeholder}
        onChange={(event) => onChange(event.target.value)}
      />
      {value && (
        <InputGroupAddon align="inline-end">
          <InputGroupButton
            size="icon-md"
            aria-label={__('Clear search', 'yaymatik')}
            onClick={() => {
              onChange('');
              input.current?.focus();
            }}
          >
            <X />
          </InputGroupButton>
        </InputGroupAddon>
      )}
    </InputGroup>
  );
}
