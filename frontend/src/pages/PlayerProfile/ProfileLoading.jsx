export default function ProfileLoading({ children }) {
    return <div className="profile-loading-state" role="status">
        <span className="profile-loading-spinner" aria-hidden="true" />
        {children}
    </div>;
}
