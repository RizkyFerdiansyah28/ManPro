const tones = { positive: 'ui-status--positive', neutral: 'ui-status--neutral', warning: 'ui-status--warning', danger: 'ui-status--danger', info: 'ui-status--info' };

export default function StatusBadge({ tone = 'neutral', className = '', children, ...props }) {
    return <span {...props} className={`ui-status ${tones[tone] ?? tones.neutral} ${className}`}>{children}</span>;
}
